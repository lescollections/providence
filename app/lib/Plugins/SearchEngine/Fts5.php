<?php
/** ---------------------------------------------------------------------
 * app/lib/Plugins/SearchEngine/Fts5.php : moteur de recherche SQLite FTS5
 * ----------------------------------------------------------------------
 * CollectiveAccess — fork lescollections
 *
 * Dérivé de SqlSearch2.php (Whirl-i-Gig, copyright 2010-2026, GPL v3) : même licence.
 * Voir « license.txt » ou http://www.gnu.org/copyleft/gpl.html
 *
 * ----------------------------------------------------------------------
 * Dessin
 *  - Copie de SqlSearch2 (27 méthodes privées, rien à hériter) dont SEUL le magasin de mots change :
 *    l'évaluation de l'AST Lucene (intersection / union / différence d'ensembles d'ids en PHP), les
 *    métadonnées typées (dates, nombres, monnaies, géo, longueurs) interrogées directement dans
 *    MariaDB, created:/modified: via ca_change_log, les filtres, quickSearch, les options et le
 *    résultat WLPlugSearchEngineSqlSearchResult sont repris tels quels.
 *  - Un fichier SQLite par instance : <search_fts5_index_dir>/recherche.sqlite (clé d'app.conf ;
 *    défaut app/tmp). WAL, synchronous=NORMAL, busy_timeout 5 s, schéma versionné (table meta).
 *  - Deux tables : `cles` — un posting = (sujet, champ, ligne de contenu, type de relation, boost,
 *    privé, vide), rowid = id — et `mots`, table FTS5 contentless (rowid = cles.id) portant le
 *    texte du champ ; `mots_vocab` (fts5vocab) sert aux jokers non préfixes et aux bornes de COUNT.
 *  - Requête : cles WHERE id IN (SELECT rowid FROM mots WHERE mots MATCH ?) — la forme IN (sous-requête)
 *    est obligatoire (une jointure directe ré-exécute MATCH par ligne). Jetons toujours entre guillemets.
 *  - Ancres = ^ $ : sentinelles xxdebutxx / xxfinxx autour du texte de chaque posting.
 *  - Normalisation, à l'indexation ET à la requête : jetons de tokenize() (celui de SqlSearch2), NFC,
 *    œ→oe æ→ae ß→ss ; minuscules et pliage des diacritiques par unicode61 (remove_diacritics 2).
 *  - Champ vide : posting cles.vide = 1 sans texte, pour que champ:[BLANK] et champ:[SET] marchent.
 * Limites
 *  - Pas de racinisation (décision projet, search_sql_search_do_stemming = 0) ; l'option est lue et
 *    signalée si active, mais sans effet.
 *  - Joker « ? », « * » intérieur, sous-chaîne (@) : résolus par le vocabulaire (GLOB), plafonnés à
 *    MAX_TERMES_JOKER termes ; dans une phrase, seul un préfixe sur le dernier mot est supporté.
 *  - Le boost est compté une fois par champ trouvé (SqlSearch2 : une fois par occurrence du mot) :
 *    mêmes ensembles, ordre par pertinence pouvant différer.
 *  - unicode61 coupe sur . - / _ : « 1916.123 » est aussi trouvé par « 123 » (SqlSearch2 non).
 *  - Les codes temporels des transcriptions ne sont pas conservés (les mots le sont).
 *  - Les jetons xxdebutxx / xxfinxx sont réservés.
 * ----------------------------------------------------------------------
 */
require_once(__CA_LIB_DIR__.'/Plugins/WLPlug.php');
require_once(__CA_LIB_DIR__.'/Plugins/IWLPlugSearchEngine.php');
require_once(__CA_LIB_DIR__.'/Plugins/SearchEngine/SqlSearchResult.php'); 
require_once(__CA_LIB_DIR__.'/Search/Common/Stemmer/SnoballStemmer.php');
require_once(__CA_APP_DIR__.'/helpers/gisHelpers.php');
require_once(__CA_LIB_DIR__.'/Plugins/SearchEngine/BaseSearchPlugin.php');

class WLPlugSearchEngineFts5 extends BaseSearchPlugin implements IWLPlugSearchEngine {
	# -------------------------------------------------------
	/** Version du schéma SQLite ; toute évolution incompatible l'incrémente et impose une reconstruction. */
	const SCHEMA_VERSION = 1;
	/** Sentinelles de début et de fin du texte d'un posting, pour les ancres =, ^ et $ (jetons réservés). */
	const DEBUT = 'xxdebutxx';
	const FIN = 'xxfinxx';
	/** Plafond de termes du vocabulaire développés pour un joker (?, * intérieur, sous-chaîne). */
	const MAX_TERMES_JOKER = 1000;
	
	private $indexing_subject_tablenum=null;
	private $indexing_subject_row_id=null;
	private $indexing_field_index = 0;
	
	private $stemmer;		// snoball stemmer (conservé pour la compatibilité de construction ; sans effet ici)
	private $do_stemming = false;
	
	private $tep;			// date/time expression parse
	
	protected $debug = false;
	
	private $get_result_desc_data = false;
	
	/** Connexion PDO SQLite à l'index, partagée entre les instances d'un même processus (par chemin) */
	private $pdo = null;
	static private $connexions = [];
	private $st_ins_cle = null;
	private $st_ins_mot = null;
	
	static public $whitespace_tokenizer_regex;
	static public $punctuation_tokenizer_regex;
	static public $separator_tokenizer_regex;
	
	static private $metadata_elements; 					// cached metadata element info
	static private $fieldnum_cache = [];				// cached field name-to-number values used when indexing
	static private $stop_words = null;
	private $doc_content_buffer = [];			// content buffer used when indexing
	
	static protected $filter_stop_words = null;
	
	static private $dict = [];
	static private $element_dicts = [];
	
	# -------------------------------------------------------
	/**
	 *
	 */
	public function __construct($db=null) {
		global $g_ui_locale;
		
		parent::__construct($db);
		
		if(is_null(self::$filter_stop_words)) { self::$filter_stop_words = $this->search_config->get('use_stop_words'); }
		
		$this->tep = new TimeExpressionParser();
		$this->tep->setLanguage($g_ui_locale);
		
		// Racinisation : le magasin FTS5 n'a pas de colonne « stem » (décision projet : pas de racinisation).
		// Si l'option est active on le signale une fois par processus et on continue sans.
		$this->stemmer = new SnoballStemmer();
		$this->do_stemming = (int)trim($this->search_config->get('search_sql_search_do_stemming')) ? true : false;
		if($this->do_stemming && !defined('__CA_FTS5_STEMMING_SIGNALE__')) {
			define('__CA_FTS5_STEMMING_SIGNALE__', 1);
			caLogEvent('WARN', 'Fts5 : search_sql_search_do_stemming est actif mais ce moteur ne racinise pas ; mettre l\'option à 0', 'Fts5');
		}
		
		if(!(self::$whitespace_tokenizer_regex = $this->search_config->get('whitespace_tokenizer_regex'))) {
			self::$whitespace_tokenizer_regex = '[\\s"“”\\—]+';
		}
		if(!(self::$punctuation_tokenizer_regex = $this->search_config->get('punctuation_tokenizer_regex'))) {
			self::$punctuation_tokenizer_regex = '[,;:\(\)\{\}\[\]\|\\\+_\!\&«»\'’]+';
		}
		if(!(self::$separator_tokenizer_regex = $this->search_config->get('separator_tokenizer_regex'))) {
			self::$separator_tokenizer_regex = '[\._\-\/]+';
		}
		
		if(self::$filter_stop_words) {
			if(!is_array(self::$stop_words)) { 
				if(CompositeCache::contains('stop_words', 'SqlSearch2')) { 
					self::$stop_words = CompositeCache::fetch('stop_words', 'SqlSearch2');
				} else {
					$sw = new \voku\helper\StopWords();
					$langs = array_map(function($v) { return array_shift(explode('_', $v)); }, ca_locales::getCataloguingLocaleCodes());
			
					self::$stop_words = [];
					foreach($langs as $lang) {
						try {
							self::$stop_words = array_merge(self::$stop_words, array_flip($sw->getStopWordsFromLanguage($lang)));
						} catch(Exception $e) {
							// noop
						}
					}
			
					// Add application-specific stop words
					self::$stop_words[mb_strtolower('['.caGetBlankLabelText(57).']')] = 1;
				
					CompositeCache::save('stop_words', self::$stop_words, 'SqlSearch2');
				}
			}
		} else {
			self::$stop_words = [];
		}
		
		//
		// Load info about metadata elements into static var cache if it hasn't already be fetched
		//
		if (!is_array(self::$metadata_elements)) {
			self::$metadata_elements = ca_metadata_elements::getRootElementsAsList();
		}
		$this->debug = false;
		
		$this->get_result_desc_data = $this->search_config->get('return_search_result_description_data');
		
		if($lists = $this->search_config->getList('expand_search_using_lists')) {
			self::$dict = self::getListsAsDict($lists);
		}
	}
	# -------------------------------------------------------
	# Initialization and capabilities
	# -------------------------------------------------------
	public function init() {
		if(($max_indexing_buffer_size = (int)$this->search_config->get('max_indexing_buffer_size')) < 1) {
			$max_indexing_buffer_size = 5000;
		}
		$this->options = array(
				'limit' => 2000,											// maximum number of hits to return [default=2000]  ** NOT CURRENTLY ENFORCED -- MAY BE DROPPED **
				'maxIndexingBufferSize' => $max_indexing_buffer_size,	// maximum number of indexed content items to accumulate before writing to the database
				'maxWordIndexInsertSegmentSize' => ceil($max_indexing_buffer_size) * 2, // maximum number of word index rows to put into a single insert
				'maxWordCacheSize' => 1048576,								// maximum number of words to cache while indexing before purging
				'cacheCleanFactor' => 0.50,									// percentage of words retained when cleaning the cache
				
				'omitPrivateIndexing' => false,								//
				'excludeFieldsFromSearch' => null,
				'restrictSearchToFields' => null,
				'strictPhraseSearching' => true,							// strict phrase searching finds only records with the precise phrase; non-strict will find fields with all of the words, in any order
				'useAsync' => true
		);
		
		// Defines specific capabilities of this engine and plug-in
		// The indexer and engine can use this information to optimize how they call the plug-in
		$this->capabilities = array(
			'incremental_reindexing' => true,		// can update indexing using only changed fields, rather than having to reindex the entire row (and related stuff) every time
			'restrict_to_fields' => true
		);
		
		if (defined('__CA_SEARCH_IS_FOR_PUBLIC_DISPLAY__')) {
			$this->setOption('omitPrivateIndexing', true); 
		}
	}
	# -------------------------------------------------------
	/**
	 * Clear internal engine caches
	 */
	public function clearCaches() : void {
		self::$fieldnum_cache = [];
		self::$metadata_elements = [];
	}
	# -------------------------------------------------------
	/**
	 * Set database connection (MariaDB : métadonnées typées, journal des modifications, filtres)
	 *
	 * @param Db $db A database connection to use in place of current one
	 */
	public function setDb($db) {
		parent::setDb($db);
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	public function __destruct() {	
		if (is_array($this->doc_content_buffer) && sizeof($this->doc_content_buffer)) {
			try {
				$this->flushContentBuffer();
			} catch(Throwable $e) {
				caLogEvent('ERR', 'Fts5 : vidage du tampon d\'indexation à la destruction : '.$e->getMessage(), 'Fts5');
			}
		}
		unset($this->config);
		unset($this->search_config);
		unset($this->db);
		unset($this->tep);
		$this->st_ins_cle = $this->st_ins_mot = null;
		$this->pdo = null;
	}
	# -------------------------------------------------------
	# Query
	# -------------------------------------------------------
	/**
	 *
	 */
	public function search(int $subject_tablenum, string $search_expression, array $filters, $rewritten_query) {
		$this->initSearch($subject_tablenum, $search_expression, $filters, $rewritten_query);
		$hits = $this->_filterQueryResult(
			$subject_tablenum, 
			$this->_processQuery($subject_tablenum, $rewritten_query), 
			$filters
		);
		if(!is_array($hits)) { $hits = []; }
		
		$hits = caSortArrayByKeyInValue($hits, ['boost'], 'desc', ['mode' => SORT_NUMERIC]); // sort by boost
		
		// Return list of hits
		return new WLPlugSearchEngineSqlSearchResult(array_keys($hits), $hits, $subject_tablenum);
	}
	# -------------------------------------------------------
	/**
	 * Dispatch query for processing
	 */
	private function _processQuery(int $subject_tablenum, $query) {
		$qclass = get_class($query);
		
		$row_ids = [];
		switch($qclass) {
			case 'Zend_Search_Lucene_Search_Query_Boolean':
				$row_ids = $this->_processQueryBoolean($subject_tablenum, $query);
				break;
			case 'Zend_Search_Lucene_Search_Query_MultiTerm':
				$row_ids = $this->_processQueryMultiterm($subject_tablenum, $query);
				break;
			case 'Zend_Search_Lucene_Search_Query_Term':
				$row_ids = $this->_processQueryTerm($subject_tablenum, $query);
				break;
			case 'Zend_Search_Lucene_Index_Term':
				$row_ids = $this->_processQueryTerm($subject_tablenum, $query);
				break;
			case 'Zend_Search_Lucene_Search_Query_Phrase':
				$row_ids = $this->_processQueryPhrase($subject_tablenum, $query);
				break;
			case 'Zend_Search_Lucene_Search_Query_Range':
				$row_ids = $this->_processQueryRange($subject_tablenum, $query);
				break;
			default:
				throw new ApplicationException(_t('Invalid query type: %1', $qclass));
				break;
		}
		
		return $row_ids;
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _processQueryBoolean(int $subject_tablenum, $query) {
		$signs = $query->getSigns();
	 	$subqueries = $query->getSubqueries();
	 	
	 	$subject_table = Datamodel::getTableName($subject_tablenum);
	 	$pk = Datamodel::primaryKey($subject_tablenum);
	 	
	 	$acc = [];
	 	$i = -1;
	 	foreach($subqueries as $subquery) {
	 		$hits = $this->_processQuery($subject_tablenum, $subquery);
	 		if(is_null($hits)) { continue; } // skip stop words
	 		$i++;
	 		$op = $this->_getBooleanOperator($signs, $i);
	 		
	 		switch($op) {
	 			case 'AND':
	 				if ($i == 0) { $acc = $hits; break; }
	 				
	 				$acc = array_intersect_key($acc, $hits);
	 				
	 				if($this->get_result_desc_data) {
						foreach($acc as $k => $v) {
							if(isset($hits[$k])) {
								$acc[$k]['index_ids'] = array_unique(array_merge($acc[$k]['index_ids'], $hits[$k]['index_ids']));
							}
						}
					}
	 				foreach($acc as $row_id => $boost) {
	 					$acc[$row_id]['boost'] += $hits[$row_id]['boost'];	// add boost
	 				}
	 				break;
	 			case 'OR':
	 				if ($i == 0) { $acc = $hits; break; }
	 				$acc = array_replace($hits, $acc);
	 				foreach($acc as $row_id => $boost) {
	 					$acc[$row_id]['boost'] += $hits[$row_id]['boost'];	// add boost
	 				}
	 				break;
	 			case 'NOT':
	 				if ($i == 0) {
	 					// TODO: Try to optimize this case by moving it from first position when possible?
	 					// 		 Without anything to diff this with we have to invert the result set, which can potentially 
	 					//		 return a very large result set
	 					if (!sizeof($hits)) { 
	 						$deleted_sql = Datamodel::getFieldNum($subject_tablenum, 'deleted') ? 'deleted = 0 ' : '';
							$qr_res = $this->db->query("
								SELECT {$pk} 
								FROM {$subject_table} 
								WHERE {$deleted_sql}
							");
	 					} else {
	 						$deleted_sql = Datamodel::getFieldNum($subject_tablenum, 'deleted') ? 'deleted = 0 AND ' : '';
							$qr_res = $this->db->query("
								SELECT {$pk} 
								FROM {$subject_table} 
								WHERE {$deleted_sql} {$pk} NOT IN (?)
							", [array_keys($hits)]);
						}
						$vals = $qr_res->getAllFieldValues($pk);
	 					
	 					$acc = [];
	 					foreach($vals as $row_id) {
	 						// assume constant boost = 1 here
	 						$acc[$row_id] = ['boost' => 1, 'index_ids' => []];
	 					}
	 				} else {
	 					$acc = array_diff_key($acc, $hits);	
	 					foreach($acc as $row_id => $boost) {
							$acc[$row_id]['boost'] += $hits[$row_id]['boost'];	// add boost
						}
	 				}
	 				break;
	 			default:
	 				throw new ApplicationException(_t('Invalid boolean operator: %1', $op));
	 				break;	
	 		}	
	 	}
	 	
	 	return $acc;
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _processQueryMultiterm(int $subject_tablenum, $query) {
		$terms = $query->getTerms();
		$signs = $query->getSigns();
		
		$acc = [];
	 	foreach($terms as $i => $term) {
	 		$hits = $this->_processQueryTerm($subject_tablenum, $term) ?? [];
	 		$op = $this->_getBooleanOperator($signs, $i);

	 		switch($op) {
	 			case 'AND':
	 				if ($i == 0) { $acc = $hits; break; }
	 				
	 				$acc = array_intersect_key($acc, $hits);
	 				foreach($acc as $row_id => $b) {
						$acc[$row_id]['boost'] += $b['boost'];	// add boost
					}
	 				break;
	 			case 'OR':
	 				if ($i == 0) { $acc = $hits; break; }
	 				$acc = array_replace($hits, $acc);
	 				foreach($acc as $row_id => $b) {
	 					$acc[$row_id]['boost'] += $b['boost'];	// add boost
	 				}
	 				break;
	 			case 'NOT':
	 				if ($i == 0) {	
						$acc = $hits; // will be negated in _processQueryBoolean()			
	 				} else {
	 					$acc = array_diff_key($acc, $hits);	
	 					foreach($acc as $row_id => $b) {
							$acc[$row_id]['boost'] += $b['boost'];	// add boost
						}
	 				}
	 				break;
	 			default:
	 				throw new ApplicationException(_t('Invalid boolean operator: %1', $op));
	 				break;	
	 		}
	 		
	 	}
	 	return $acc;
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _processQueryTerm(int $subject_tablenum, $query) {
		$qclass = get_class($query);
		$term = ($qclass === 'Zend_Search_Lucene_Search_Query_Term') ? $query->getTerm() : $query;
		
	 	$field = $term->field;
	 	$field_lc = mb_strtolower($field);
	 	$field_elements = explode('.', $field_lc);
	 	if (in_array($field_elements[0], ['created', 'modified', _t('created'), _t('modified')])) {
	 		return $this->_processQueryChangeLog($subject_tablenum, $term);
	 	}
	 	$ap = $field ? $this->_getElementIDForAccessPoint($subject_tablenum, $field) : null;
	 	$words = [$term->text];
	 	
	 	if($field && !is_array($ap)) {
	 		$words[0] = $field.':'.$words[0];
	 		$field = null;
	 	}
	 	$indexing_options = caGetOption('indexing_options', $ap, null);
	 	
	 	$blank_val = caGetBlankLabelText($subject_tablenum);
	 	$is_blank = ((mb_strtolower("[{$blank_val}]") === mb_strtolower($term->text)) || (mb_strtolower("[BLANK]") === mb_strtolower($term->text)));
	 	$is_not_blank = (mb_strtolower("["._t('SET')."]") === mb_strtolower($term->text));
	 	
	 	if(!$is_blank && !$is_not_blank && (!is_array($indexing_options) || !in_array('DONT_TOKENIZE', $indexing_options) || in_array('INDEX_AS_IDNO', $indexing_options))) {
	 		$words = self::filterStopWords(self::tokenize(join(' ', $words), true));
	 	}
	 	
	 	$words = array_filter($words, 'strlen');
	 	if(!$words || !sizeof($words)) { return null; }
	 	
	 	if (is_array($ap) && !$this->useSearchIndexForAP($ap)) {
	 		// Handle datatype-specific queries
	 		$ret = $this->_processMetadataDataType($subject_tablenum, $ap, $query);
	 		if(is_array($ret)) { return $ret; }
	 	}
	 	
	 	$anchor_mode = null;
	 	if(!is_null($words[0]) && strlen($words[0]) && !is_null($anchor_mode = $this->_getAnchorMode($words[0]))) {
			$words[0] = mb_substr($words[0], 1);
		}
		
		if(is_array($ap) && ($ap['expand_search_using_list'] ?? null)) {
			$dict = self::getListsAsDict($ap['expand_search_using_list']);
		} else {
			$dict = self::$dict;
		}
		if(is_array($syns = ($dict[mb_strtolower($words[0])] ?? null))) {
			$syns = array_map(function($v) { return self::tokenize($v); }, $syns);
			$words[0] = [$words[0]];
			$words = array_merge($words, $syns);
		}
	 	$results = [];
	 	foreach($words as $i => $wl) {
	 		if(!is_array($wl)) { $wl = [$wl]; }
	 		if((sizeof($wl) > 1) && ($i > 0)){	// treat expansion terms as quoted phrases
	 			$q = new Zend_Search_Lucene_Search_Query_Phrase($wl, null, $field);
	 			$results[] = $this->_processQueryPhrase($subject_tablenum, $q);
	 		} else {
				foreach($wl as $w => $text) {
					// SqlSearch2 racinise ici (sauf marque « | » finale, valeurs vides, non-lettres) ; pas de
					// racinisation dans ce moteur : on retire seulement la marque.
					$text = preg_replace("!\|$!", '', $text);
					
					$this->searched_terms[] = $text;
					
					$boost = null;			// null = boost du posting ; sinon constante (SqlSearch2 : 100)
					$match = null;			// expression FTS5 ; null = pas de contrainte sur les mots
					$conditions = [];
					$params = [];
					
					if (is_array($ap) && $is_blank) {
						$conditions[] = 'c.vide = 1';
						$boost = 100;
					} elseif (!is_array($ap) && $is_blank) {
						return [];
					} elseif(is_array($ap) && $is_not_blank) {
						$conditions[] = 'c.vide = 0';
						$boost = 100;
					} elseif ($text === '*') {
						// Joker nu : toutes les lignes de la table (repris de SqlSearch2)
						$t = Datamodel::getInstance($subject_tablenum, true);
						$pk = $t->primaryKey();
						$table = $t->tableName();
						$qr_res = $this->db->query("
							SELECT 0 index_id, {$pk} row_id, 100 boost
							FROM {$table}".($t->hasField('deleted') ? " WHERE deleted = 0" : "")."
						", []);
						$results[] = $this->_arrayFromDbResult($qr_res);
						continue;
					} else {
						if((strpos($text, '*') !== false) || (strpos($text, '?') !== false)) { $boost = 100; }
						$match = $this->_expressionMatchMot($text, $anchor_mode);
						if(is_null($match)) { $results[] = []; continue; }	// aucun mot du vocabulaire ne correspond
					}
					
					if (is_array($ap)) {
						if($ap['datatype'] === __CA_ATTRIBUTE_VALUE_CONTAINER__) {
							$element_ids = ca_metadata_elements::getElementsForSet($ap['element_id'], ['idsOnly' => true]);
							if(!is_array($element_ids) || !sizeof($element_ids)) {
								$element_ids = [$ap['element_id']];
							}
							$conditions[] = "c.field_table_num = ? AND c.field_num IN (".self::_marques(sizeof($element_ids)).")";
							$params[] = (int)$ap['table_num'];
							foreach($element_ids as $eid) { $params[] = "A{$eid}"; }
						} else {
							$conditions[] = "c.field_table_num = ? AND c.field_num = ?";
							$params[] = (int)$ap['table_num'];
							$params[] = (string)$ap['field_num'];
						}
					
						if (is_array($ap['relationship_type_ids']) && sizeof($ap['relationship_type_ids'])) {
							$conditions[] = "c.rel_type_id IN (".self::_marques(sizeof($ap['relationship_type_ids'])).")";
							foreach($ap['relationship_type_ids'] as $rid) { $params[] = (int)$rid; }
						}
					}
					if($r = $this->_sqlRestrictions($subject_tablenum)) {
						$conditions[] = $r[0];
						$params = array_merge($params, $r[1]);
					}
					$results[] = $this->_rechercherPostings($subject_tablenum, $match, $conditions, $params, $boost);
				}
			}
		}
		
		$ret = array_shift($results);
		foreach($results as $r) {
			if(!is_array($r)) { continue; }
			$ret = ($ret + $r);
		}
		return $ret;
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _processQueryPhrase(int $subject_tablenum, $query) {
	 	$terms = $query->getTerms();
	 
	 	$force_strict = false;
	 	if($terms[0]->text[0] === '~') {
	 		$terms[0]->text = substr($terms[0]->text, 1);
	 		$force_strict = true;
	 	}
	 	
	 	$term = $terms[0];
	 	$field = $term->field;
	 	$field_lc = mb_strtolower($field);
	 	$field_elements = explode('.', $field_lc);
	 	if (in_array($field_elements[0], ['created', 'modified', _t('created'), _t('modified')])) {
	 		return $this->_processQueryChangeLog($subject_tablenum, $query);
	 	}
	 	
	 	if ($this->getOption('strictPhraseSearching') || $force_strict) {
	 		$words = [];
	 		$ap_spec = null;
	 		
			foreach($terms as $term) {
				if (!$ap_spec && ($field = $term->field)) { $ap_spec = $field; }
				
				if (strlen($text = join(' ', self::tokenize($term->text, true)))) {
					$words[] = $text;
					$this->searched_terms[] = $text;
				}
			}
			
			if (!sizeof($words)) { return []; }
			
			$phrases = [$words];
			$phr = trim(mb_strtolower(join(' ', $words)));
			
			$ap = $this->_getElementIDForAccessPoint($subject_tablenum, $ap_spec);
			if(is_array($ap) && ($ap['expand_search_using_list'] ?? null)) {
				$dict = self::getListsAsDict($ap['expand_search_using_list']);
			} else {
				$dict = self::$dict;
			}
			if(is_array($syns = $dict[$phr] ?? null)) {
				$syns = array_map(function($v) { return self::tokenize($v); }, $syns);
				$phrases = array_merge($phrases, $syns);	
			}
				
			$acc = [];	
			foreach($phrases as $words) {	
				$anchor_mode = null;
				if(!is_null($anchor_mode = $this->_getAnchorMode($words[0]))) {
					$words[0] = mb_substr($words[0], 1);
				}
			
				$ap_tmp = explode(".", $ap_spec);
				$conditions = [];
				$params = [];
				if(is_array($ap_tmp) && (sizeof($ap_tmp) >= 2)) {
					if (is_array($ap)) {
						// Handle datatype-specific queries
						$ret = $this->_processMetadataDataType($subject_tablenum, $ap, $query);
						if(is_array($ret)) { return $ret; }
					}
					if (isset($ap['field_num'], $ap['table_num'])) {
						$conditions[] = "c.field_table_num = ? AND c.field_num = ?";
						$params[] = (int)$ap['table_num'];
						$params[] = (string)$ap['field_num'];
						
						if (is_array($ap['relationship_type_ids']) && sizeof($ap['relationship_type_ids'])) {
							$conditions[] = "c.rel_type_id IN (".self::_marques(sizeof($ap['relationship_type_ids'])).")";
							foreach($ap['relationship_type_ids'] as $rid) { $params[] = (int)$rid; }
						}
					}
				}
				
				// Remove empty words and bare wildcards - have no meaning in phrase search
				$words = array_values(array_filter($words, function($v) {
					$v = preg_replace("![\*\? ]+!", "", $v);
					return strlen($v);
				}));
				if(!sizeof($words)) { return []; }
				
				// Jokers dans une phrase : FTS5 n'accepte qu'un préfixe sur le dernier mot ; ailleurs le joker est retiré.
				$prefixe = false;
				$n = sizeof($words);
				foreach($words as $w => $word) {
					$word = self::_normaliser($word);
					if(($w === $n - 1) && preg_match('!^([^*?]+)\*$!u', $word, $m) && !in_array($anchor_mode, ['EXACT', 'END'], true)) {
						$word = $m[1];
						$prefixe = true;
					} else {
						$word = str_replace(['*', '?'], '', $word);
					}
					$words[$w] = $word;
				}
				if(($anchor_mode === 'START') && !$prefixe && (bool)$this->search_config->get('add_wildcard_on_begins_searches')) {
					$prefixe = true;
				}
				$match = $this->_phraseFts($words, $anchor_mode, $prefixe);
				
				if($r = $this->_sqlRestrictions($subject_tablenum)) {
					$conditions[] = $r[0];
					$params = array_merge($params, $r[1]);
				}
				
				$acc += $this->_rechercherPostings($subject_tablenum, $match, $conditions, $params, 1);
			}
			return $acc;
	 	} else {
	 		$acc = [];
	 		$i = 0;
			foreach($terms as $term) {
				$hits = $this->_processQueryTerm($subject_tablenum, $term);
				if(!is_array($hits)) { continue; }
				if ($i == 0) { $i++; $acc = $hits; continue; }
				$acc = array_intersect_key($acc, $hits);
				$i++;
			}
			return $acc;
	 	}
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _processQueryChangeLog(int $subject_tablenum, Object $term) {
		switch(get_class($term)) {
			case 'Zend_Search_Lucene_Search_Query_Term':
			case 'Zend_Search_Lucene_Index_Term':
	 			$text = $term->text;
				$field = $term->field;
	 			break;
	 		case 'Zend_Search_Lucene_Search_Query_Phrase':
	 			$text = join(' ', array_map(function($t) { return $t->text; }, $terms = $term->getTerms()));
				$field = $terms[0]->field;
	 			break;
	 	}
	 	$text = str_replace('*', '', $text);	// strip wildcards
	 	$field_lc = mb_strtolower($field);
	 	$field_elements = explode('.', $field_lc);
	 	if (in_array($field_elements[0], ['created', 'modified', _t('created'), _t('modified')])) {
	 		if (!$this->tep->parse($text)) { return []; }
	 		$range = $this->tep->getUnixTimestamps();
			$user_id = null;
			$user_sql = '';
			if (sizeof($field_elements) > 1) {
				if (!is_int($field_elements[1])) {
					$t_user = new ca_users();
					if (
						$t_user->load(["user_name" => $field_elements[1]])
						||
						((strpos($field_elements[1], "_") !== false) && $t_user->load(["user_name" => str_replace("_", " ", $field_elements[1])]))
					) {
						$user_id = (int)$t_user->getPrimaryKey();
					}
				} else {
					$user_id = (int)$field_elements[1];
				}
				$user_sql = ($user_id)  ? " AND (ccl.user_id = {$user_id})" : "";
			}

			switch($field_elements[0]) { 
				case _t('created'):
				case 'created':
					$qr_res = $this->db->query("
							SELECT ccl.logged_row_id row_id, 1 boost
							FROM ca_change_log ccl
							WHERE
								(ccl.log_datetime BETWEEN ? AND ?)
								AND
								(ccl.logged_table_num = ?)
								AND
								(ccl.changetype = 'I')
								{$user_sql}
						", [(int)$range['start'], (int)$range['end'], $subject_tablenum]);
					break;
				case _t('modified'):
				case 'modified':
					$qr_res = $this->db->query("
							SELECT '_change_log_' as index_id, ccl.logged_row_id row_id, 1 boost
							FROM ca_change_log ccl
							WHERE
								(ccl.log_datetime BETWEEN ? AND ?)
								AND
								(ccl.logged_table_num = ?)
								AND
								(ccl.changetype = 'U')
								{$user_sql}
						UNION
							SELECT '_change_log_' as index_id, ccls.subject_row_id row_id, 1 boost
							FROM ca_change_log ccl
							INNER JOIN ca_change_log_subjects AS ccls ON ccls.log_id = ccl.log_id
							WHERE
								(ccl.log_datetime BETWEEN ? AND ?)
								AND
								(ccls.subject_table_num = ?)
								{$user_sql}
						", [(int)$range['start'], (int)$range['end'], $subject_tablenum, (int)$range['start'], (int)$range['end'], $subject_tablenum]);
					break;
				default:
					throw new ApplicationException(_t('Invalid change log search mode: %1', $field));
					break;
			}
			
	 		return $this->_arrayFromDbResult($qr_res);
	 	}
	 	return [];
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _processQueryRange(int $subject_tablenum, Zend_Search_Lucene_Search_Query_Range $query) {
	 	$lower_term = $query->getLowerTerm();
		$upper_term = $query->getUpperTerm();
		$lower_text = $lower_term->text;
		$upper_text = $upper_term->text;
		
		$ap = $this->_getElementIDForAccessPoint($subject_tablenum, $lower_term->field);
		if (!is_array($ap)) { return []; }
		
		if ($ap['datatype'] === 'COUNT') {
			// Les comptes sont indexés comme texte ; les bornes se résolvent par le vocabulaire (comparaison numérique).
			$termes = $this->_termesDuVocabulaire(null, "CAST(term AS INTEGER) BETWEEN ? AND ? AND term GLOB '[0-9]*'", [(int)$lower_text, (int)$upper_text]);
			if(!sizeof($termes)) { return []; }
			$match = join(' OR ', array_map(function($t) { return '"'.str_replace('"', '""', $t).'"'; }, $termes));
			
			$conditions = ["c.field_table_num = ?", "c.field_num = ?"];
			$params = [(int)$ap['table_num'], (string)$ap['field_num']];
			if(is_array($ap['relationship_type_ids']) && sizeof($ap['relationship_type_ids'])) {
				$conditions[] = "c.rel_type_id IN (".self::_marques(sizeof($ap['relationship_type_ids'])).")";
				foreach($ap['relationship_type_ids'] as $rid) { $params[] = (int)$rid; }
			}
			return $this->_rechercherPostings($subject_tablenum, $match, $conditions, $params, null);
		}
		$table = Datamodel::getTableName($subject_tablenum);
		$idno_fld = Datamodel::getTableProperty($subject_tablenum, 'ID_NUMBERING_ID_FIELD');
		if($lower_term->field === "{$table}.{$idno_fld}") {
			if($o_idno = IDNumbering::newIDNumberer($table)) {
				$idno_sort_fld = Datamodel::getTableProperty($subject_tablenum, 'ID_NUMBERING_SORT_FIELD');
				if(($t_subject = Datamodel::getInstance($table, true)) && ($t_subject->hasField("{$idno_sort_fld}_num"))) {
					$lower_index = $o_idno->getSortableNumericValue($lower_text);
					$upper_index = $o_idno->getSortableNumericValue($upper_text);
			
					$pk = Datamodel::primaryKey($table);
				
					$params = [
						(int)$lower_index, (int)$upper_index
					];
					$qr_res = $this->db->query("
						SELECT '_idno_' as index_id, t.{$pk} row_id, 100 boost
						FROM {$table} t
						WHERE
							t.{$idno_sort_fld}_num BETWEEN ? AND ?
					", $params);
					return $this->_arrayFromDbResult($qr_res);
				}
			}
		}
		return $this->_processMetadataDataType($subject_tablenum, $ap, $query);
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _processMetadataDataType(int $subject_tablenum, array $ap, $query) { 
		$qclass = get_class($query);
		
		$text = $text_upper = null;
		switch($qclass) {
			case 'Zend_Search_Lucene_Search_Query_Term':
				$term = $query->getTerm();
				$text = $term->text;
				break;
			case 'Zend_Search_Lucene_Index_Term':
				$text = $query->text;
				break;
			case 'Zend_Search_Lucene_Search_Query_Phrase':
				$terms = $query->getTerms();
				$text = join(' ', array_map(function($t) { return $t->text;}, $terms));
				break;
			case 'Zend_Search_Lucene_Search_Query_Range':
				$lower_term = $query->getLowerTerm();
				$upper_term = $query->getUpperTerm();
				$text = $lower_term->text;
				$text_upper = $upper_term->text;
				break;
			default:
				throw new ApplicationException(_t('Invalid query passed to _processMetadataDataType: %1', $qclass));
				break;
		}
		
		if (!($t_instance = Datamodel::getInstance($ap['table_num'], true))) { return []; }
		
		// is field intrinsic? (dates, integer, numerics can be intrinsic)
		if($ap['type'] === 'INTRINSIC') {
			$field = explode('.', $ap['access_point']);
			$field_name = $field[1];
			$table = $field[0];
			
			if(!$t_instance->hasField($field_name)) { return []; }
			$fi = $t_instance->getFieldInfo($field_name);
			
			switch($fi['FIELD_TYPE']) {
				case FT_NUMBER:
					$ap['element_info']['datatype'] = isset($fi['LIST_CODE']) ? null : __CA_ATTRIBUTE_VALUE_NUMERIC__;
					break;
				case FT_HISTORIC_DATERANGE:
				case FT_DATERANGE:
					$ap['element_info']['datatype'] = __CA_ATTRIBUTE_VALUE_DATERANGE__;
					break;
				default:
					return null;	// Don't process here - use search index
			}
		}
		
		$qinfo = null;
		switch($ap['element_info']['datatype']) {
	 		case __CA_ATTRIBUTE_VALUE_DATERANGE__:
				$qinfo = $this->_queryForDateRangeAttribute(new DateRangeAttributeValue(), $ap, $text, $text_upper, ['t_subject' => $t_instance]);
				break;
			case __CA_ATTRIBUTE_VALUE_TIMECODE__:
				$qinfo = $this->_queryForNumericAttribute(new TimeCodeAttributeValue(), $ap, $text, $text_upper, 'value_decimal1', ['t_subject' => $t_instance]);
				break;
			case __CA_ATTRIBUTE_VALUE_LENGTH__:
				$qinfo = $this->_queryForNumericAttribute(new LengthAttributeValue(), $ap, $text, $text_upper, 'value_decimal1', ['t_subject' => $t_instance]);
				break;
			case __CA_ATTRIBUTE_VALUE_WEIGHT__:
				$qinfo = $this->_queryForNumericAttribute(new WeightAttributeValue(), $ap, $text, $text_upper, 'value_decimal1', ['t_subject' => $t_instance]);
				break;
			case __CA_ATTRIBUTE_VALUE_INTEGER__:
				$qinfo = $this->_queryForNumericAttribute(new NumericAttributeValue(), $ap, $text, $text_upper, 'value_integer1', ['t_subject' => $t_instance]);
				break;
			case __CA_ATTRIBUTE_VALUE_NUMERIC__:
				$qinfo = $this->_queryForNumericAttribute(new NumericAttributeValue(), $ap, $text, $text_upper, 'value_decimal1', ['t_subject' => $t_instance]);
				break;
			case __CA_ATTRIBUTE_VALUE_CURRENCY__:
				$qinfo = $this->_queryForCurrencyAttribute(new CurrencyAttributeValue(), $ap, $text, $text_upper, ['t_subject' => $t_instance]);
				break;
			case __CA_ATTRIBUTE_VALUE_GEOCODE__:
			case __CA_ATTRIBUTE_VALUE_GEONAMES__:
				$qinfo = $this->_queryForGeocodeAttribute(new GeocodeAttributeValue(), $ap, $text, $text_upper, ['t_subject' => $t_instance]);
				break;
		}
		if(is_array($qinfo) && sizeof($qinfo)) {
			foreach($qinfo['params'] as $params) {
				if($ap['type'] !== 'INTRINSIC') { array_unshift($params, $ap['table_num']); }
				$qr_res = $this->db->query($qinfo['sql'], $params);
				if($qr_res && ($qr_res->numRows() > 0)) { break; }
			}
			
			$row_ids = $this->_arrayFromDbResult($qr_res);
			unset($ap['element_info']);
			
			foreach($row_ids as $row_id => $row_info) {
				$row_ids[$row_id]['access_point'] = [
					'ap' => $ap['access_point'],
					'table' => Datamodel::getTableName($ap['table_num']),
					'field_row_id' => $row_id,
					'field_num' => $ap['field_num'],
					'word' => $text
				];
			}
			
			if ((int)$ap['table_num'] === (int)$subject_tablenum) {
				return $row_ids;
			}
			
			$s = Datamodel::getInstance($subject_tablenum, true);
			$spk = $s->primaryKey(true);
			
			// convert related ids to subject
			$ap_instance = Datamodel::getInstance($ap['table_num'], true);
			
			// it's labels, so first convert labels to their subject-table ids... and then we convert that to the search subject
			if(is_a($ap_instance, 'BaseLabel')) {
				$ap_subject = $ap_instance->getSubjectTableInstance();
				$aspk = $ap_subject->primaryKey();
				
				$qr = $this->db->query("
					SELECT {$aspk} FROM {$ap_instance->tableName()} WHERE {$ap_instance->primaryKey()} IN (?)
				", [array_keys($row_ids)]);
				
				$row_ids = [];
				while($qr->nextRow()) {
					$row_ids[(int)$qr->get($aspk)] = 1;
				}
				$ap['table_num'] = $ap_subject->tableNum();
			}
			
			$subject_ids = [];
			
			if(!($qr = caMakeSearchResult($ap['table_num'], array_keys($row_ids)))) { return []; }
		
			while($qr->nextHit()) {
				switch((int)$ap['table_num']) {
					case 103:
						$s = $qr->getInstance();
						$a = $s->getItems(['idsOnly' => true]);
						break;
					default:
						$a = $qr->get($spk, ['restrictToRelationshipTypes' => $ap['relationship_type_ids'] ?? null, 'returnAsArray' => true]);
						break;
				}
				
				foreach($a as $i) {
					$subject_ids[$i] = 1;
				}
			}	
			
			return array_map(function($v) { return ['row_id' => $v, 'boost' => 1, 'index_ids' => []]; }, $subject_ids);
		}
		return null;	// can't process here - try using search index
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _filterQueryResult(int $subject_tablenum, ?array $hits, array $filters) {
		if (is_array($filters) && sizeof($filters) && is_array($hits) && sizeof($hits)) {
			if (!($t_instance = Datamodel::getInstance($subject_tablenum, true))) {
				throw new ApplicationException(_t('Invalid subject table: %1', $subject_tablenum));
			}
			$table_name = $t_instance->tableName();
			
			$joins = [];
			foreach($filters as $filter) {
				$tmp = explode('.', $filter['field']);
				$path = [];
				
				if(!($fi = Datamodel::getInstance($tmp[0], true))) { continue; }
				if(!$fi->hasField($tmp[1])) { continue; }
			
				if ($tmp[0] !== $table_name) {
					$path = Datamodel::getPath($table_name, $tmp[0]);
				} 
				if (is_array($path) && sizeof($path)) {
					$last_table = $table_name;
					// generate related joins
					foreach($path as $table => $va_info) {
						if($table == $table_name) { continue; }
						if (!($t_table = Datamodel::getInstance($table, true))) {
							throw new ApplicationException(_t('Invalid path table: %1', $table));
						}
						$rels = Datamodel::getOneToManyRelations($last_table, $table);
						if (!is_array($rels) || !sizeof($rels)) {
							$rels = Datamodel::getOneToManyRelations($table, $last_table);
						}
						if ($table == $rels['one_table']) {
							$joins[$table] = "INNER JOIN ".$rels['one_table']." ON ".$rels['one_table'].".".$rels['one_table_field']." = ".$rels['many_table'].".".$rels['many_table_field'];
						} else {
							$joins[$table] = "INNER JOIN ".$rels['many_table']." ON ".$rels['many_table'].".".$rels['many_table_field']." = ".$rels['one_table'].".".$rels['one_table_field'];
						}
						
						$last_table = $table;
					}
					$sql_where = "(".$filter['field']." ".$filter['operator']." ".$this->_filterValueToQueryValue($filter).")";
				} else {
					if(!($t_table = Datamodel::getInstanceByTableName($tmp[0], true))) {
						throw new ApplicationException(_t('Invalid path table: %1', $table));
					}
					$sql_where = "(".$filter['field']." ".$filter['operator']." ".$this->_filterValueToQueryValue($filter).")";
				}
			
				switch($filter['operator']) {
					case 'in':
						if (strpos(strtolower($filter['value']), 'null') !== false) {
							$sql_where = "({$sql_where} OR (".$filter['field']." IS NULL))";
						}
						break;
					case 'not in':
						if (strpos(strtolower($filter['value']), 'null') !== false) {
							$sql_where = "({$sql_where} OR (".$filter['field']." IS NOT NULL))";
						}
						break;
				}
				$wheres[] = $sql_where;
			}
			
			$pk = $t_instance->primaryKey(true);
			$table = $t_instance->tableName();
			$sql_joins = join("\n", $joins);
			$qr_res = $this->db->query("
				SELECT {$pk} 
				FROM {$table} 
				{$sql_joins} 
				WHERE {$pk} IN (?) AND ".join(' AND ', $wheres), [array_keys($hits)]);
				
			$filtered_hits = array_flip($qr_res->getAllFieldValues($pk));
			return array_intersect_key($hits, $filtered_hits);
		}
		
		return $hits;
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _getFieldRestrictions(int $table_num) {
		$restrict_to_fields = $exclude_fields_from_search = [];
		if(is_array($this->getOption('restrictSearchToFields'))) {
			foreach($this->getOption('restrictSearchToFields') as $f) {
				$restrict_to_fields[] = $this->_getElementIDForAccessPoint($table_num, $f);
			}
		}
		if(is_array($this->getOption('excludeFieldsFromSearch'))) {
			foreach($this->getOption('excludeFieldsFromSearch') as $f) {
				$exclude_fields_from_search[] = $this->_getElementIDForAccessPoint($table_num, $f);
			}
		}
		return ['restrict' => $restrict_to_fields, 'exclude' => $exclude_fields_from_search];
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _getAnchorMode(string $word) : ?string {
		$anchor_mode = null;
		switch(mb_substr($word, 0, 1)) {
			case '=':
				$anchor_mode = 'EXACT';
				break;
			case '^':
				$anchor_mode = 'START';
				break;
			case '$':
				$anchor_mode = 'END';
				break;
			case '@':
				$anchor_mode = 'CONTAINS';
				break;
		}
		return $anchor_mode;
	}
	# -------------------------------------------------------
	# Indexing
	# -------------------------------------------------------
	/**
	 *
	 */
	public function startRowIndexing(int $subject_tablenum, int $subject_row_id) : void {
		$this->indexing_subject_tablenum = $subject_tablenum;
		$this->indexing_subject_row_id = $subject_row_id;
		$this->indexing_field_index = 0;
	}
	# -------------------------------------------------------
	/**
	 * Met en tampon un posting (sujet courant, champ, ligne de contenu) portant le texte du champ.
	 * Le texte est fait des jetons de tokenize() (ou du contenu brut si DONT_TOKENIZE), normalisés,
	 * entre sentinelles ; un champ vide donne un posting sans texte, marqué vide = 1.
	 */
	public function indexField(int $content_tablenum, string $content_fieldname, int $content_row_id, $content, ?array $options=null) {
		if (!is_array($options)) { $options = []; }
		
		if($this->indexing_field_index < 16777216 && !caGetOption('dontIncrementFieldIndex', $options, false)) { $this->indexing_field_index++; }
		
		if (!is_array($content)) {
			$content = [$content];
		}
		
		$boost = 1;
		if (isset($options['BOOST'])) {
			$boost = intval($options['BOOST']);
		}
		if($boost < 0) { $boost = 0; }
		if($boost > 255) { $boost = 255; }
		
		if (in_array('DONT_TOKENIZE', array_values($options), true)) { 
			$options['DONT_TOKENIZE'] = true;  
		} elseif (!isset($options['DONT_TOKENIZE'])) { 
			$options['DONT_TOKENIZE'] = false; 
		}
		
		$force_tokenize = (in_array('TOKENIZE', array_values($options), true) || isset($options['TOKENIZE']));
		$tokenize = $options['DONT_TOKENIZE'] ? false : true;
		
		$rel_type_id = (isset($options['relationship_type_id']) && ($options['relationship_type_id'] > 0)) ? (int)$options['relationship_type_id'] : 0;
		if($rel_type_id > 65535) { $rel_type_id = 0; } // disregard if out of bound; can happen with set items where rel_type_id isn't really relevant
		$container_id = (isset($options['container_id']) && ($options['container_id'] > 0)) ? (int)$options['container_id'] : null;
		
		if (!isset($options['PRIVATE'])) { $options['PRIVATE'] = 0; }
		if (in_array('PRIVATE', $options, true)) { $options['PRIVATE'] = 1; }
		$private = $options['PRIVATE'] ? 1 : 0;
		
		if (!isset($options['datatype'])) { $options['datatype'] = null; }
		
		$transcribed_content = null;
		
		if ($content_fieldname[0] == 'A') {
			$field_num_proc = (int)substr($content_fieldname, 1);
			
			// do we need to index this (don't index attribute types that we'll search directly)
			if (self::$metadata_elements[$field_num_proc] ?? null) {
				switch(self::$metadata_elements[$field_num_proc]['datatype']) {
					case __CA_ATTRIBUTE_VALUE_CONTAINER__:	
					case __CA_ATTRIBUTE_VALUE_GEOCODE__:	
					case __CA_ATTRIBUTE_VALUE_GEONAMES__:	
					case __CA_ATTRIBUTE_VALUE_CURRENCY__:
					case __CA_ATTRIBUTE_VALUE_LENGTH__:
					case __CA_ATTRIBUTE_VALUE_WEIGHT__:
					case __CA_ATTRIBUTE_VALUE_TIMECODE__:
					case __CA_ATTRIBUTE_VALUE_MEDIA__:
					case __CA_ATTRIBUTE_VALUE_FILE__:
						return;
				}
			}
		} elseif(($content_fieldname[0] == 'I') && ($t_instance = Datamodel::getInstance($content_tablenum, true))) {
			$fn = Datamodel::getFieldName($content_tablenum, (int)substr($content_fieldname, 1));
			$field_info = $t_instance->getFieldInfo($fn);
			
			if($field_info['TRANSCRIBED_CONTENT'] ?? false) {
				if(is_array($d = json_decode($content[0] ?? '', true))) {
					$transcribed_content = $d;
				}
			}
		}
		
		if ((!is_array($content) && !strlen($content)) || !sizeof($content) || (((sizeof($content) == 1) && strlen((string)$content[0]) == 0)) || ((sizeof($content) === 1) && ((string)mb_strtolower($content[0]) === mb_strtolower(caGetBlankLabelText(Datamodel::getTableName($content_tablenum)))))){ 
			$words = null;
		} else {
			// Tokenize string
			$words = [];
			if ($tokenize || $force_tokenize) {
				foreach($content as $c) {
					$c = str_replace("<", " ", $c);
					$c = str_replace(">", " ", $c);
					$words = array_merge($words, self::tokenize((string)$c));
				}
			}
			if (!$tokenize) { $words = array_merge($words, $content); }
		}
		
		$incremental_reindexing = (bool)$this->can('incremental_reindexing');
		
		if (!defined("__CollectiveAccess_IS_REINDEXING__") && $incremental_reindexing && !($options['dontRemoveExistingIndexing'] ?? false)) {
			$this->removeRowIndexing($this->indexing_subject_tablenum, $this->indexing_subject_row_id, $content_tablenum, array($content_fieldname), $content_row_id, $rel_type_id);
		}
		
		if(is_array($transcribed_content)) {
			// Transcription : on garde les mots, pas les codes temporels
			$words = [];
			foreach($transcribed_content as $w) {
				$words = array_merge($words, self::tokenize((string)($w['word'] ?? '')));
			}
		}
		
		$txt = is_array($words) ? self::_texteIndex($words) : null;
		$this->doc_content_buffer[] = [
			(int)$this->indexing_subject_tablenum, (int)$this->indexing_subject_row_id,
			(int)$content_tablenum, (string)$content_fieldname, $container_id, (int)$content_row_id,
			$rel_type_id, is_null($txt) ? 0 : $boost, $private, is_null($txt) ? 1 : 0, $txt
		];
	}
	# ------------------------------------------------
	/**
	 *
	 */
	public function commitRowIndexing() : void {
		if (sizeof($this->doc_content_buffer) > $this->getOption('maxIndexingBufferSize')) {
			$this->flushContentBuffer();
		}
	}
	# ------------------------------------------------
	/**
	 * Écrit le tampon de postings dans l'index, en une transaction.
	 */
	public function flushContentBuffer() : void {
		if (!is_array($this->doc_content_buffer) || !sizeof($this->doc_content_buffer)) {
			$this->doc_content_buffer = [];
			return;
		}
		$db = $this->_bd();
		$this->_preparerInsertions($db);
		
		$db->beginTransaction();
		try {
			foreach($this->doc_content_buffer as $p) {
				$txt = array_pop($p);
				$this->st_ins_cle->execute($p);
				if(!is_null($txt)) {
					$this->st_ins_mot->execute([(int)$db->lastInsertId(), $txt]);
				}
			}
			$db->commit();
		} catch(PDOException $e) {
			if($db->inTransaction()) { $db->rollBack(); }
			$this->doc_content_buffer = [];
			throw new ApplicationException(_t('Fts5: indexing write failed: %1', $e->getMessage()));
		}
		$this->doc_content_buffer = [];
	}
	# ------------------------------------------------
	/**
	 * Désindexe, aux six granularités de SqlSearch2 (sujet ; sujet + champ ; sujet + ligne de contenu ;
	 * sujet + champ + ligne ; contenu seul pour les dépendants ; sujet + type de relation). Les postings
	 * encore en tampon qui répondent aux mêmes critères sont retirés aussi (SqlSearch2 ne le fait pas :
	 * deux passages sur la même ligne dans un même processus y doublonnent l'indexation).
	 */
	public function removeRowIndexing(?int $subject_tablenum, ?int $pn_subject_row_id, ?int $pn_field_tablenum=null, $pa_field_nums=null, ?int $pn_field_row_id=null, ?int $pn_rel_type_id=null) {
		$vn_rel_type_id = $pn_rel_type_id ? $pn_rel_type_id : 0;
		
		$lots = [];	// critères colonne => valeur, sur cles
		// remove dependent row indexing
		if ($subject_tablenum && $pn_subject_row_id &&  !is_null($pn_field_tablenum) && !is_null($pn_field_row_id) && is_array($pa_field_nums) && sizeof($pa_field_nums)) {
			foreach($pa_field_nums as $pn_field_num) {
				if(!$pn_field_num) { continue; }
				$lots[] = ['table_num' => (int)$subject_tablenum, 'row_id' => (int)$pn_subject_row_id, 'field_table_num' => (int)$pn_field_tablenum, 'field_num' => (string)$pn_field_num, 'field_row_id' => (int)$pn_field_row_id, 'rel_type_id' => $vn_rel_type_id];
			}
		} elseif ($subject_tablenum && $pn_subject_row_id && !is_null($pn_field_tablenum) && !is_null($pn_field_row_id)) {
			$lots[] = ['table_num' => (int)$subject_tablenum, 'row_id' => (int)$pn_subject_row_id, 'field_table_num' => (int)$pn_field_tablenum, 'field_row_id' => (int)$pn_field_row_id, 'rel_type_id' => $vn_rel_type_id];
		} elseif (!is_null($pn_field_tablenum) && is_array($pa_field_nums) && sizeof($pa_field_nums)) {
			foreach($pa_field_nums as $pn_field_num) {
				if(!$pn_field_num) { continue; }
				
				if (!is_null($pn_rel_type_id)) {
					$lots[] = ['table_num' => (int)$subject_tablenum, 'row_id' => (int)$pn_subject_row_id, 'field_table_num' => (int)$pn_field_tablenum, 'field_num' => (string)$pn_field_num, 'rel_type_id' => $vn_rel_type_id];
				} else {
					$lots[] = ['table_num' => (int)$subject_tablenum, 'row_id' => (int)$pn_subject_row_id, 'field_table_num' => (int)$pn_field_tablenum, 'field_num' => (string)$pn_field_num];
				}
			}
		} elseif (!$subject_tablenum && !$pn_subject_row_id && !is_null($pn_field_tablenum) && !is_null($pn_field_row_id)) {
			$lots[] = ['field_table_num' => (int)$pn_field_tablenum, 'field_row_id' => (int)$pn_field_row_id, 'rel_type_id' => $vn_rel_type_id];
		} elseif($vn_rel_type_id > 0) {
			$lots[] = ['table_num' => (int)$subject_tablenum, 'row_id' => (int)$pn_subject_row_id, 'rel_type_id' => $vn_rel_type_id];
		} else {
			$lots[] = ['table_num' => (int)$subject_tablenum, 'row_id' => (int)$pn_subject_row_id];
		}
		return $this->_supprimerPostings($lots);
	}
	# ------------------------------------------------
	/**
	 *
	 *
	 * @param int $subject_tablenum
	 * @param array $pa_subject_row_ids
	 * @param int $pn_content_tablenum
	 * @param string $ps_content_fieldnum
	 * @param int $pn_content_row_id
	 * @param string $ps_content
	 * @param array $pa_options
	 *		literalContent = array of text content to be applied without tokenization
	 *		BOOST = Indexing boost to apply
	 *		PRIVATE = Set indexing to private
	 */
	public function updateIndexingInPlace($subject_tablenum, $pa_subject_row_ids, $pn_content_tablenum, $ps_content_fieldnum, $pn_content_container_id, $pn_content_row_id, $ps_content, $pa_options=null) {
		if(!is_array($pa_options)) { $pa_options = []; }
		// Find existing indexing for this subject and content 	
		foreach($pa_subject_row_ids as $vn_subject_row_id) {
			$this->removeRowIndexing($subject_tablenum, $vn_subject_row_id, $pn_content_tablenum, array($ps_content_fieldnum), $pn_content_row_id, caGetOption('relationship_type_id', $pa_options, null));
		}
		
		if (caGetOption("DONT_TOKENIZE", $pa_options, false) || in_array('DONT_TOKENIZE', $pa_options, true)) {
			$va_words = array($ps_content);
		} else {
			$va_words = self::tokenize($ps_content);
		}
		
		if((sizeof($va_words) === 1) && (mb_strtolower((string)$va_words[0]) === mb_strtolower(caGetBlankLabelText(Datamodel::getTableName($pn_content_tablenum))))) {
			$va_words = null;
		} elseif (caGetOption("INDEX_AS_IDNO", $pa_options, false) || in_array('INDEX_AS_IDNO', $pa_options, true)) {
			$t_content = Datamodel::getInstanceByTableNum($pn_content_tablenum, true);
			
			$va_values = [];
			if ($delimiters = caGetOption("IDNO_DELIMITERS", $pa_options, false)) {
				if ($delimiters && !is_array($delimiters)) { $delimiters = [$delimiters]; }
				if ($delimiters) {
					$va_values = array_map(function($v) { return trim($v); }, preg_split('!('.join('|', $delimiters).')!', $ps_content));
				} 
			}
			if (!sizeof($va_values) && method_exists($t_content, "getIDNoPlugInInstance") && ($o_idno = $t_content->getIDNoPlugInInstance())) {
				$va_values = $o_idno->getIndexValues($ps_content);
			}
			$va_words += $va_values;
		}
		
		$va_literal_content = caGetOption("literalContent", $pa_options, null);
		if ($va_literal_content && !is_array($va_literal_content)) { $va_literal_content = array($va_literal_content); }
		
		$vn_boost = 1;
		if (isset($pa_options['BOOST'])) {
			$vn_boost = intval($pa_options['BOOST']);
		}
		
		if (!isset($pa_options['PRIVATE'])) { $pa_options['PRIVATE'] = 0; }
		if (in_array('PRIVATE', $pa_options, true)) { $pa_options['PRIVATE'] = 1; }
		$vn_private = $pa_options['PRIVATE'] ? 1 : 0;
		
		$vn_rel_type_id = (int)caGetOption('relationship_type_id', $pa_options, 0);
		if($vn_rel_type_id > 65535) { $vn_rel_type_id = 0; } // disregard if out of bound; can happen with set items where rel_type_id isn't really relevant
		
		$subject_tablenum = (int)$subject_tablenum;
		$pn_content_tablenum = (int)$pn_content_tablenum;
		$pn_content_row_id = (int)$pn_content_row_id;
		$vn_boost = (int)$vn_boost;
		$container_id = $pn_content_container_id ? (int)$pn_content_container_id : null;

		$transcribed_content = null;
		if(($ps_content_fieldnum[0] == 'I') && ($t_instance = Datamodel::getInstance($pn_content_tablenum, true))) {
			$fn = Datamodel::getFieldName($pn_content_tablenum, (int)substr($ps_content_fieldnum, 1));
			$field_info = $t_instance->getFieldInfo($fn);
			
			if($field_info['TRANSCRIBED_CONTENT'] ?? false) {
				if(is_array($d = json_decode($ps_content ?? '', true))) {
					$transcribed_content = $d;
				}
			}
		}
		
		// Un seul texte pour toutes les lignes sujets : mots, puis contenu littéral
		if(is_array($transcribed_content)) {
			$mots = [];
			foreach($transcribed_content as $w) {
				$mots = array_merge($mots, self::tokenize((string)($w['word'] ?? '')));
			}
		} elseif(is_array($va_words)) {
			$mots = array_values(array_filter($va_words, function($v) { return !is_null($v); }));
			if (is_array($va_literal_content)) {
				$mots = array_merge($mots, $va_literal_content);
			}
		} else {
			$mots = null;
		}
		$txt = is_array($mots) ? self::_texteIndex($mots) : null;
		
		$postings = [];
		foreach($pa_subject_row_ids as $vn_row_id) {
			if (!$vn_row_id) { continue; }
			$postings[] = [
				$subject_tablenum, (int)$vn_row_id, $pn_content_tablenum, (string)$ps_content_fieldnum, $container_id, $pn_content_row_id,
				$vn_rel_type_id, is_null($txt) ? 0 : $vn_boost, $vn_private, is_null($txt) ? 1 : 0, $txt
			];
		}
		
		// do insert
		if (sizeof($postings)) {
			$db = $this->_bd();
			$this->_preparerInsertions($db);
			$db->beginTransaction();
			try {
				foreach($postings as $p) {
					$txt = array_pop($p);
					$this->st_ins_cle->execute($p);
					if(!is_null($txt)) {
						$this->st_ins_mot->execute([(int)$db->lastInsertId(), $txt]);
					}
				}
				$db->commit();
			} catch(PDOException $e) {
				if($db->inTransaction()) { $db->rollBack(); }
				throw new ApplicationException(_t('Fts5: indexing write failed: %1', $e->getMessage()));
			}
		}				
	}
	# -------------------------------------------------
	/**
	 * Vide le tampon puis fusionne les segments FTS5 ; appelé par le réindexeur après chaque table.
	 */
	public function optimizeIndex(int $tablenum) {
		$this->flushContentBuffer();
		try {
			$db = $this->_bd();
			$db->exec("INSERT INTO mots(mots) VALUES('optimize')");
			$db->exec("PRAGMA optimize");
		} catch(PDOException $e) {
			caLogEvent('ERR', 'Fts5 : optimisation de l\'index : '.$e->getMessage(), 'Fts5');
		}
	}
	# --------------------------------------------------
	/**
	 * 
	 */
	public function engineName() {
		return 'Fts5';
	}
	# --------------------------------------------------
	/**
	 * Tokenize string for indexing or search
	 *
	 * @param string $content
	 * @param bool $for_search
	 * @param int $index
	 *
	 * @return array Tokenized terms
	 */
	static public function tokenize(?string $content, ?bool $for_search=false, ?int $index=0) : array {
		if(!self::$whitespace_tokenizer_regex) {
			self::$whitespace_tokenizer_regex = caGetSearchConfig()->get('whitespace_tokenizer_regex');
		}
		$content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
		$content = preg_replace('![\']+!u', '', $content);		// strip apostrophes for compatibility with SearchEngine class, which does the same to all search expressions

		switch($alphabet = caIdentifyAlphabet($content)) {
			case 'HAN':
				if(class_exists("\Binaryoung\Jieba\Jieba")) {
					$words = \Binaryoung\Jieba\Jieba::cut($content);
					$words = array_map(function($v) {
						$w = str_replace('·', ' ', html_entity_decode($v, null, 'UTF-8'));
						$w = preg_replace('!'.self::$punctuation_tokenizer_regex.'!u', '', $w);
						return mb_strtolower($w);
					}, $words);
					break;
				}
			default:
				$words = preg_split('!'.self::$whitespace_tokenizer_regex.'!u', strip_tags(br2nl($content)));
				$words = array_map(function($v) {
					$w = preg_replace('!'.self::$punctuation_tokenizer_regex.'!u', '', html_entity_decode($v, null, 'UTF-8'));
					$w = preg_replace('!^'.self::$separator_tokenizer_regex.'!u', '', $w);
					$w = preg_replace('!'.self::$separator_tokenizer_regex.'$!u', '', $w);
					return mb_strtolower($w);
				}, $words);
				break;
		}
		
		$words = self::filterStopWords($words);
		$words = array_values(array_filter(array_map(function_exists('mb_trim') ? 'mb_trim' : 'trim', $words), 'strlen'));
		return $words;
	}
	# --------------------------------------------------
	/**
	 *
	 */
	static public function filterStopWords(array $words) : array {
		if(!self::$filter_stop_words) { return $words; }
		return array_filter($words, function($v) {
			return (strlen($v) && !array_key_exists($v, self::$stop_words));
		});
	}
	# --------------------------------------------------
	/**
	 * Performs the quickest possible search on the index for the specfied table_num in $pn_table_num
	 * using the text in $ps_search. Unlike the search() method, quickSearch doesn't support
	 * any sort of search syntax. You give it some text and you get a collection of (hopefully) relevant results back quickly. 
	 * quickSearch() is intended for autocompleting search suggestion UI's and the like, where performance is critical
	 * and the ability to control search parameters is not required.
	 *
	 * @param $pn_table_num - The table index to search on
	 * @param $ps_search - The text to search on
	 * @param $pa_options - an optional associative array specifying search options. Supported options are: 'limit' (the maximum number of results to return)
	 *
	 * @return Array - an array of results is returned keyed by primary key id. The array values boolean true. This is done to ensure no duplicate row_ids
	 * 
	 */
	public function quickSearch($pn_table_num, $ps_search, $pa_options=null) {
		if (!is_array($pa_options)) { $pa_options = array(); }
		$vs_limit_sql = '';
		if (isset($pa_options['limit']) && ($pa_options['limit'] > 0)) { 
			$vs_limit_sql = 'LIMIT '.(int)$pa_options['limit'];
		}
		
		$va_hits = array();
		$va_words = self::tokenize($ps_search, true);
		
		$phrases = [];
		foreach($va_words as $vs_word) {
			$vs_word = self::_normaliser($vs_word);
			if(!preg_match('/[\p{L}\p{N}]/u', $vs_word)) { continue; }
			$phrases[] = $this->_phraseFts([$vs_word], null, false);
		}
		if (sizeof($phrases)) {
			try {
				$st = $this->_bd()->prepare("
					SELECT c.row_id
					FROM cles c
					WHERE
						c.table_num = ?
						AND
						c.id IN (SELECT rowid FROM mots WHERE mots MATCH ?)
						".($this->getOption('omitPrivateIndexing') ? " AND c.prive = 0" : '')."
					GROUP BY c.row_id
					ORDER BY sum(c.boost) DESC
					{$vs_limit_sql}
				");
				$st->execute([(int)$pn_table_num, join(' OR ', $phrases)]);
				$va_hits = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
			} catch(PDOException $e) {
				caLogEvent('ERR', 'Fts5 : quickSearch : '.$e->getMessage(), 'Fts5');
				return [];
			}
		}
		return $va_hits;
	}
	# -------------------------------------------------------
	/**
	 * Completely clear index (usually in preparation for a full reindex)
	 *
	 * @param int $pn_table_num Table_num of table to truncate from index; if omitted index for all tables is truncated.
	 * @return bool Returns true
	 */
	public function truncateIndex($pn_table_num=null) {
		$this->doc_content_buffer = [];
		if ($pn_table_num > 0) {
			$this->_supprimerPostings([['table_num' => (int)$pn_table_num]]);
		} else {
			$db = $this->_bd();
			try {
				$db->exec("DELETE FROM cles");
				$db->exec("INSERT INTO mots(mots) VALUES('delete-all')");
			} catch(PDOException $e) {
				throw new ApplicationException(_t('Fts5: cannot truncate index: %1', $e->getMessage()));
			}
			try {
				$db->exec("VACUUM");	// rend la place ; peut échouer si un lecteur est actif, sans gravité
			} catch(PDOException $e) {
				// noop
			}
		}
		return true;
	}	
	# --------------------------------------------------
	# Utils
	# --------------------------------------------------
	private function getFieldNum($pn_table_name_or_num, $ps_fieldname) {
		if (isset(self::$fieldnum_cache[$pn_table_name_or_num.'/'.$ps_fieldname])) { return self::$fieldnum_cache[$pn_table_name_or_num.'/'.$ps_fieldname]; }
		
		$vs_table_name = is_numeric($pn_table_name_or_num) ? Datamodel::getTableName((int)$pn_table_name_or_num) : (string)$pn_table_name_or_num;
		return self::$fieldnum_cache[$pn_table_name_or_num.'/'.$ps_fieldname] = Datamodel::getFieldNum($vs_table_name, $ps_fieldname);
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _filterValueToQueryValue(array $filter) : string {
		switch(strtolower($filter['operator'])) {
			case '>':
			case '<':
			case '=':
			case '>=':
			case '<=':
			case '<>':
				return (int)$filter['value'];
				break;
			case 'in':
			case 'not in':
				$tmp = explode(',', $filter['value']);
				$values = array();
				foreach($tmp as $t) {
					if ($t == 'NULL') { continue; }
					$values[] = (int)preg_replace("![^\d]+!", "", $t);
				}
				return "(".join(",", $values).")";
				break;
			case 'is':
			case 'is not':
			default:
				return is_null($filter['value']) ? 'NULL' : (string)$filter['value'];
				break;
		}
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _getElementIDForAccessPoint($subject_tablenum, $access_point) {
		$tmp = preg_split('![/\|]+!', $access_point);
		list($table, $field, $subfield, $subsubfield, $subsubsubfield) = array_pad(explode('.', $tmp[0]), 5, null);
		if ($table === '_fulltext') { return null; }	// ignore "_fulltext" specifier – just treat as text search
		
		$rel_table = caGetRelationshipTableName($subject_tablenum, $table);
		$rel_type_ids = (is_array($tmp) && sizeof($tmp) && ($tmp[1] ?? null) && $rel_table) ? caMakeRelationshipTypeIDList($rel_table, preg_split("![,;]+!", $tmp[1])) : [];
		
		if (!($t_table = Datamodel::getInstanceByTableName($table, true))) { 
			if(in_array($table, caSearchGetTablesForAccessPoints([$tmp[0]]))) {
				return ['access_point' => $tmp[0]];
			}
			return null;
		}
		
		if((mb_strtolower($field) === 'related') && $rel_table) {
			$spec = explode('.', $tmp[0]);
			$table = array_shift($spec);
			array_shift($spec);
			$tmp = preg_split('![/\|]+!', join('.', array_merge([$table], $spec)));
			list($field, $subfield, $subsubfield, $subsubsubfield) = array_pad($spec, 4 , null);
		}
		
		if (in_array(strtolower($field), ['preferred_labels', 'nonpreferred_labels'])) {
			$t_table = $t_table->getLabelTableInstance();
			$table = $t_table->tableName();
			if(!($field = $subfield)) { $field = $t_table->getDisplayField(); }
			$subfield = $subsubfield = $subsubsubfield = null;
			
			$tmp[0] = join('.', [$table, $field]);
		}
		
		$table_num = $t_table->tableNum();
		
		// counts for relationship
		$vn_rel_type = null;
		
		if (is_array($rel_type_ids) && (sizeof($rel_type_ids) > 0)) {
			$vn_rel_type = (int)$rel_type_ids[0];
		}
		
		if(is_array($indexing_info = $this->search_indexing_config->get(Datamodel::getTableName($subject_tablenum)))) {
			$indexing_info = $indexing_info[$table]['fields'][$field] ?? null;
		}
		if (strtolower($field) == 'count') {
			if (!is_array($rel_type_ids) || !sizeof($rel_type_ids)) { $rel_type_ids = [0]; }	// for counts must pass "0" as relationship type to pull count for all reltypes in aggregate
			return array(
				'access_point' => "{$table}.{$field}",
				'relationship_type' => $vn_rel_type,
				'table_num' => $table_num,
				'element_id' => null,
				'field_num' => 'COUNT',
				'datatype' => 'COUNT',
				'element_info' => null,
				'relationship_type_ids' => $rel_type_ids,
				'type' => 'COUNT',
				'indexing_options' => $indexing_info
			);
		} elseif (strtolower($field) == 'current_value') {
		    if(!$subfield) { $subfield = '__default__'; }
		    
		    $fld_num = null;
		    if ($vn_fld_num = $this->getFieldNum($table, $subsubsubfield ? $subsubsubfield : $subsubfield)) {
		        $fld_num = "I{$vn_fld_num}";
		    } elseif($t_element = ca_metadata_elements::getInstance($subsubsubfield ? $subsubsubfield : $subsubfield)) {
		        $fld_num = "A".$t_element->getPrimaryKey();
		    }
		    return array(
				'access_point' => $tmp[0],
				'relationship_type' => $vn_rel_type,
				'table_num' => $table_num,
				'element_id' => null,
				'field_num' => $fld_num ? "CV{$subfield}_{$fld_num}" : "CV{$subfield}",
				'datatype' => 'CV',
				'element_info' => null,
				'relationship_type_ids' => $rel_type_ids,
				'policy' => $subfield,
				'type' => 'CV',
				'indexing_options' => $indexing_info
			);
		
		} elseif (is_numeric($field)) {
			$fld_num = $field;
		} else {
			$fld_num = $this->getFieldNum($table, $field);
		}
		
		if (!strlen($fld_num)) {
			$t_element = new ca_metadata_elements();
			
			$vb_is_count = false;
			if(strtolower($subfield) == 'count') {
				$subfield = null;
				$vb_is_count = true;
				if (!is_array($rel_type_ids) || !sizeof($rel_type_ids)) { $rel_type_ids = [0]; }
			}
			if ($t_element->load(array('element_code' => ($subfield ? $subfield : $field)))) {
				if ($vb_is_count) {
					return array(
						'access_point' => "{$table}.{$field}",
						'relationship_type' => $tmp[1],
						'table_num' => $table_num,
						'element_id' => $t_element->getPrimaryKey(),
						'field_num' => 'COUNT'.$t_element->getPrimaryKey(),
						'datatype' => 'COUNT',
						'element_info' => $t_element->getFieldValuesArray(),
						'relationship_type_ids' => $rel_type_ids,
						'type' => 'COUNT',
						'indexing_options' => $indexing_info
					);
				} else {
					return array(
						'access_point' => $tmp[0] ?? null,
						'relationship_type' => $tmp[1] ?? null,
						'table_num' => $table_num,
						'element_id' => $t_element->getPrimaryKey(),
						'field_num' => 'A'.$t_element->getPrimaryKey(),
						'datatype' => $t_element->get('datatype'),
						'element_info' => $t_element->getFieldValuesArray(),
						'relationship_type_ids' => $rel_type_ids,
						'type' => 'METADATA',
						'expand_search_using_list' => $t_element->getSetting('expandSearchUsingList'),
						'indexing_options' => $indexing_info
					);
				}
			}
		} else {
			return array('access_point' => $tmp[0] ?? null, 'relationship_type' => $tmp[1] ?? null, 'table_num' => $table_num, 'field_num' => 'I'.$fld_num, 'field_num_raw' => $fld_num, 'datatype' => null, 'relationship_type_ids' => $rel_type_ids, 'type' => 'INTRINSIC', 'indexing_options' => $indexing_info);
		}

		return null;
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _getBooleanOperator(?array $signs, int $index) {
		if (is_null($signs ?? null) || (($signs[$index] ?? null) === true)) {	
			// if array is null then according to Zend Lucene all subqueries should be "are required"... so we AND them
			return "AND";
		} elseif (is_null($signs[$index] ?? null)) {	
			// is the sign for a particular query is null then OR it is (it is "neither required nor prohibited")
			return 'OR';
		} else {
			// true sign indicates "required" (AND) operation, false indicates "prohibited" (NOT) operation
			return (($signs[$index] ?? null) === false) ? 'NOT' : 'AND';	
		}
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _arrayFromDbResult(DbResult $qr_res) {
		$vals = $qr_res->getAllFieldValues(['index_id', 'row_id', 'boost']);
	 	if(!isset($vals['row_id'])) { return []; }
	 	$hits = [];
	 	foreach($vals['row_id'] as $i => $row_id) {
	 		if(!isset($hits[$row_id])) { 
	 			$hits[$row_id]['boost'] = 0; 
	 			$hits[$row_id]['index_ids'] = []; 
	 		}
	 		$hits[$row_id]['boost'] += ($vals['boost'][$i] ?? 0);
	 		
	 		if(!$vals['index_id'][$i]) { continue; }
	 		
	 		if(($max_index_count = (int)$this->search_config->get('search_result_description_maximum_index_matches')) < 1) {
	 			$max_index_count = 3;
	 		}
	 		
	 		if(($this->get_result_desc_data  && sizeof($hits[$row_id]['index_ids']) < $max_index_count)) {
	 			$hits[$row_id]['index_ids'][] = $vals['index_id'][$i];
	 		}
	 	}
	 	return $hits;
	}
	# -------------------------------------------------------
	/**
	 * Description des correspondances (return_search_result_description_data) : le champ, la ligne de contenu
	 * et le type de relation du posting ; pas le mot lui-même (table FTS5 sans contenu).
	 */
	public function _resolveHitInformation($res) {
		if(!$this->get_result_desc_data) { return []; }
		$index_ids = array_unique(array_reduce($res, function($c, $v) {
			$ids = array_filter($v['index_ids'] ?? [], 'is_numeric');
			return array_merge($c, $ids);
		}, []));
		
		if(sizeof($index_ids)) {
			$index_ids = array_values(array_map('intval', $index_ids));
			try {
				$st = $this->_bd()->prepare("
					SELECT c.id index_id, c.row_id, c.field_table_num, c.field_num, c.field_row_id, c.rel_type_id, c.field_container_id
					FROM cles c
					WHERE c.id IN (".self::_marques(sizeof($index_ids)).")
				");
				$st->execute($index_ids);
			} catch(PDOException $e) {
				caLogEvent('ERR', 'Fts5 : _resolveHitInformation : '.$e->getMessage(), 'Fts5');
				return $res;
			}
	
			while($row = $st->fetch(PDO::FETCH_ASSOC)) {
				$row['table'] = Datamodel::getTableName($row['field_table_num']);
		
				$row_id = $row['row_id'];
				unset($row['row_id']);
		
				$res[$row_id]['desc'][] = $row;
				unset($res[$row_id]['index_ids']);
			}
		}
		return $res;
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _queryForNumericAttribute($attrval, $ap, $text, $text_upper, $attr_field, ?array $options=null) {
		list($text, $modifier) = $this->parseModifier($text);
		if (!is_array($parsed_value = $attrval->parseValue($text, $ap['element_info']))) {
			return null;
		}
		
		if (!in_array($attr_field, ['value_integer1', 'value_decimal1'])) { 
			throw new ApplicationException(_t('Invalid attribute field'));
		}
		
		$t_subject = caGetOption('t_subject', $options, null);
		
		$parsed_value_end = $text_upper ? $attrval->parseValue($text_upper, $ap['element_info']) : null;
				
		if($ap['type'] === 'INTRINSIC') {
			$tmp = explode('.', $ap['access_point']);
			if (!($t_table = Datamodel::getInstance($tmp[0], true))) {
				throw new ApplicationException(_t('Invalid table %1 in bundle %2', $tmp[0], $ap['access_point']));
			}
			
			$pk = $t_table->primaryKey(true);
			$table = $t_table->tableName();
			$field = $tmp[1];
			
			if(!$t_table->hasField($field)) { 
				throw new ApplicationException(_t('Invalid field %1 in bundle %2', $field, $ap['access_point']));
			}
		} else {
			$field = 'cav.'.$attr_field;
		}
		
		$sql_where = null;
		switch($modifier) {
			case '#gt#':
				$sql_where = "({$field} > ?)"; 
				$params = [$parsed_value['value_decimal1']];
				break;
			case '#gt=':
				$sql_where = "({$field} >= ?)"; 
				$params = [$parsed_value['value_decimal1']];
				break;
			case '#lt#':
				$sql_where = "({$field} < ?)"; 
				$params = [$parsed_value['value_decimal1']];
				break;
			case '#lt=':
				$sql_where = "({$field} <= ?)"; 
				$params = [$parsed_value['value_decimal1']];
				break;
			case '#eq#':
			default:
				if($parsed_value_end) {
					$sql_where = "({$field} >= ? AND {$field} <= ?)";
					$params = [$parsed_value['value_decimal1'], $parsed_value_end['value_decimal1']];
				} else {
					$params = [$parsed_value['value_decimal1']];
					if($parsed_value['value_decimal1'] === 0.0) {
						$sql_where = "(({$field} = ?) OR ({$field} IS NULL))";
					} else {
						$sql_where = "({$field} = ?)";
					}
				}
				break;
		}
		
		$join_sql = $deleted_sql = '';
		if($t_subject->hasField('deleted')) {
			$t = $t_subject->tableName();
			$t_pk = $t_subject->primaryKey();
			$join_sql = " INNER JOIN {$t} AS t ON t.{$t_pk} = ca.row_id";
			$deleted_sql = " AND (t.deleted = 0)";
		}
		
		if($ap['type'] === 'INTRINSIC') {
			if($deleted_sql) { $deleted_sql = " AND (deleted = 0)"; }
			$sql = "
				SELECT {$pk} row_id, 1 boost
				FROM {$table}
				WHERE
					{$sql_where}
					{$deleted_sql}
			";
		} else {
			$sql = "
				SELECT ca.row_id, 1 boost
				FROM ca_attribute_values cav
				INNER JOIN ca_attributes AS ca ON ca.attribute_id = cav.attribute_id
				{$join_sql}
				WHERE
					(cav.element_id = {$ap['element_info']['element_id']}) AND (ca.table_num = ?)
					AND
					{$sql_where}
					{$deleted_sql}
			";
		}
		
		return ['sql' => $sql, 'params' => [$params]];
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _queryForCurrencyAttribute($attrval, $ap, $text, $text_upper, ?array $options=null) {
		list($text, $modifier) = $this->parseModifier($text);
		if (!is_array($parsed_value = $attrval->parseValue($text, $ap['element_info']))) {
			return null;
		}
		
		$currency = preg_replace('![^A-Z0-9]+!', '', $parsed_value['value_longtext1']);
		if (!$currency) { 
			return null;	// no currency
		}
		
		$t_subject = caGetOption('t_subject', $options, null);
		
		$parsed_value_end = $text_upper ? $attrval->parseValue($text_upper, $ap['element_info']) : null;
		
		$sql_where = null;
		switch($modifier) {
			case '#gt#':
				$sql_where = "(cav.value_decimal1 > ? AND cav.value_longtext1 = ?)";
				$params = [$parsed_value['value_decimal1'], $currency];
				break;
			case '#gt=':
				$sql_where = "(cav.value_decimal1 >= ? AND cav.value_longtext1 = ?)";
				$params = [$parsed_value['value_decimal1'], $currency];
				break;
			case '#lt#':
				$sql_where = "(cav.value_decimal1 < ? AND cav.value_longtext1 = ?)";
				$params = [$parsed_value['value_decimal1'], $currency];
				break;
			case '#lt=':
				$sql_where = "(cav.value_decimal1 <= ? AND cav.value_longtext1 = ?)";
				$params = [$parsed_value['value_decimal1'], $currency];
				break;
			case '#eq#':
			default:
				if($parsed_value_end) {
					$sql_where = "((cav.value_decimal1 >= ? AND cav.value_decimal1 <= ?) AND (cav.value_longtext1 = ?))";
					$params = [$parsed_value['value_decimal1'], $parsed_value_end['value_decimal1'], $currency];
				} else {
					$sql_where = "(cav.value_decimal1 = ? AND cav.value_longtext1 = ?)";
					$params = [$parsed_value['value_decimal1'], $currency];
				}
				break;
		}
		
		$join_sql = $deleted_sql = '';
		if($t_subject->hasField('deleted')) {
			$t = $t_subject->tableName();
			$t_pk = $t_subject->primaryKey();
			$join_sql = " INNER JOIN {$t} AS t ON t.{$t_pk} = ca.row_id";
			$deleted_sql = " AND (t.deleted = 0)";
		}

		$sql = "
			SELECT ca.row_id, 1 boost
			FROM ca_attribute_values cav
			INNER JOIN ca_attributes AS ca ON ca.attribute_id = cav.attribute_id
			{$join_sql}
			WHERE
				(cav.element_id = {$ap['element_info']['element_id']}) AND (ca.table_num = ?)
				AND
				{$sql_where}
				{$deleted_sql}
		";
		
		return ['sql' => $sql, 'params' => [$params]];
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _queryForGeocodeAttribute($attrval, $ap, $text, $text_upper, ?array $options) {
		$upper_lat = $upper_long = $lower_lat = $lower_long = null;
		if(!$text && $text_upper) {
			$text = $text_upper;
			$text_upper = null;
		}
		
		$t_subject = caGetOption('t_subject', $options, null);
		
		$params = [];
		$mode = null;
		
		if ($text) {
			if(
				is_array($rewrites = $this->search_config->get('geocode_search_rewrites'))
				&&
				!preg_match("!^\[.*\]$!", $text)	// don't rewrite coordinates
			) {
				$rtext = $text;
				$matched = false;
				foreach($rewrites as $rcode => $rinfo) {
					if(!is_array($regexes = $rinfo['regexes'] ?? null)) { continue; }
					foreach($regexes as $match => $repl) {
						$rtext = @preg_replace("{$match}", "{$repl}", $rtext);
						if(is_null($rtext)) { continue; }
						if($rtext !== $text) { $matched = true; }
					}
					
					if($matched) { 
						$mode = $rinfo['mode'] ?? null;
						break;
					}
				}
				$text = $rtext;
			}
			
			if(is_array($parsed_values = caParseGISSearch($text))) {
				$lower_lat = $parsed_values['min_latitude'];
				$upper_lat = $parsed_values['max_latitude'];
				$lower_long = $parsed_values['min_longitude'];
				$upper_long = $parsed_values['max_longitude'];
				$params[] = [$lower_lat, $upper_lat, $lower_long, $upper_long];
			} else {
				$parse_opts = ['returnBounds' => false];
				switch($mode) {
					case 'postcode':
						$parse_opts['geocoderType'] = 'postcode';
						break;
				}
				if (!is_array($parsed_value = $attrval->parseValue($text, $ap['element_info'], $parse_opts))) {
					return null;
				}
				
				$upper_lat = $upper_long = null;
				if(isset($parsed_value['bounds'])) {
					$lower_lat = (float)$parsed_value['bounds']['south'];
					$lower_long = (float)$parsed_value['bounds']['west'];
					$upper_lat = (float)$parsed_value['bounds']['north'];
					$upper_long = (float)$parsed_value['bounds']['east'];
				} else {
					$lower_lat = (float)$parsed_value['value_decimal1'];
					$lower_long = (float)$parsed_value['value_decimal2'];
				}
				
				$utype = $parsed_value['type'] ?? null;
				$radius_by_type =  $this->search_config->get('geocode_search_radius_by_type');
				if(!is_array($default_search_radius = $this->search_config->getList('geocode_search_default_radius')) || !sizeof($default_search_radius)) {
					$default_search_radius = ["500m"];
				}
			
				if(isset($radius_by_type[$utype])) {
					$default_search_radius = is_array($radius_by_type[$utype]) ? $radius_by_type[$utype] : [$radius_by_type[$utype]];
				}
				if($text_upper) {
					$parsed_value = $attrval->parseValue($text_upper, $ap['element_info'], $parse_opts);
					$upper_lat = (float)$parsed_value['value_decimal1'];
					$upper_long = (float)$parsed_value['value_decimal2'];
				} elseif(!$upper_lat || !$upper_long) {
					foreach($default_search_radius as $radius) {
						$parsed_values = caParseGISSearch("[{$lower_lat},{$lower_long} ~ {$radius}]");
						$lower_lat = $parsed_values['min_latitude'];
						$upper_lat = $parsed_values['max_latitude'];
						$lower_long = $parsed_values['min_longitude'];
						$upper_long = $parsed_values['max_longitude'];
						
						// MySQL BETWEEN always wants the lower value first ... BETWEEN 5 AND 3 wouldn't match 4 ... So we swap the values if necessary
						if($upper_lat < $lower_lat) {
							$tmp = $upper_lat;
							$upper_lat = $lower_lat;
							$lower_lat = $tmp;
						}
						if($upper_long < $lower_long) {
							$tmp = $upper_long;
							$upper_long = $lower_long;
							$lower_long = $tmp;
						}
						
						$params[] = [$lower_lat, $upper_lat, $lower_long, $upper_long];
					}
				}
				
				if(!sizeof($params)) {
					$upper_lat = $lower_lat;
					$upper_long = $lower_long;
					
					$upper_lat += .01;
					$upper_long += .01;
					$lower_lat -= .01;
					$lower_long -= .01;
					
					// MySQL BETWEEN always wants the lower value first ... BETWEEN 5 AND 3 wouldn't match 4 ... So we swap the values if necessary
					if($upper_lat < $lower_lat) {
						$tmp = $upper_lat;
						$upper_lat = $lower_lat;
						$lower_lat = $tmp;
					}
					if($upper_long < $lower_long) {
						$tmp = $upper_long;
						$upper_long = $lower_long;
						$lower_long = $tmp;
					}
					$params[] = [$lower_lat, $upper_lat, $lower_long, $upper_long];
				}
			}
		} else {
			return [];
		}
		
		$sql_where = '';
		
		if (!is_null($upper_lat) && !is_null($upper_long)) {
			$sql_where = "((cav.value_decimal1 >= ? AND cav.value_decimal1 <= ?) AND (cav.value_decimal2 >= ? AND cav.value_decimal2 <= ?))";
		} else {
			throw new ApplicationException(_t('Upper lat/long coordinates must not be empty'));
		}
		
		$join_sql = $deleted_sql = '';
		if($t_subject->hasField('deleted')) {
			$t = $t_subject->tableName();
			$t_pk = $t_subject->primaryKey();
			$join_sql = " INNER JOIN {$t} AS t ON t.{$t_pk} = ca.row_id";
			$deleted_sql = " AND (t.deleted = 0)";
		}
		
		$sql = "
			SELECT ca.row_id, 1 boost
			FROM ca_attribute_values cav
			INNER JOIN ca_attributes AS ca ON ca.attribute_id = cav.attribute_id
			{$join_sql}
			WHERE
				(cav.element_id = {$ap['element_info']['element_id']}) AND (ca.table_num = ?)
				AND
				({$sql_where})
				{$deleted_sql}
		";
		return ['sql' => $sql, 'params' => $params];
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function _queryForDateRangeAttribute($attrval, $ap, $text, $text_upper, ?array $options=null) {
		list($text, $modifier) = $this->parseModifier($text);
		
		if ($text_upper) { $text = "{$text} - {$text_upper}"; }
		if (!is_array($parsed_value = $attrval->parseValue($text, $ap['element_info']))) {
			return null;
		}
		
		$t_subject = caGetOption('t_subject', $options, null);
		
		$dates = [
			'start' => $parsed_value['value_decimal1'],
			'end' => $parsed_value['value_decimal2']
		];
		if (((int)$dates['start'] === -2000000000) && $this->search_config->get('treat_before_dates_as_circa')) {
			$dates['start'] = (int)$dates['end'] + 0.1231235959;
		}
		if (((int)$dates['end'] === 2000000000) && $this->search_config->get('treat_after_dates_as_circa')) {
			$dates['end'] = (int)$dates['start'];
		}
		
		$dates['start'] = (float)$dates['start'];
		$dates['end'] = (float)$dates['end'];

		$sfield = $efield = $params = null;
		
		if($ap['type'] === 'INTRINSIC') {
			$tmp = explode('.', $ap['access_point']);
			if (!($t_table = Datamodel::getInstance($tmp[0], true))) {
				throw new ApplicationException(_t('Invalid table %1 in bundle %2', $tmp[0], $ap['access_point']));
			}
			
			$pk = $t_table->primaryKey(true);
			$table = $t_table->tableName();
			
			$fi = $t_table->getFieldInfo($tmp[1]);
			
			$sfield = $table.'.'.$fi['START'];
			$efield = $table.'.'.$fi['END'];
		} else {
			$sfield = 'cav.value_decimal1';
			$efield = 'cav.value_decimal2';
			
			$params = [$dates['start'], $dates['end'], $dates['start'], $dates['end'], $dates['start'], $dates['end']];
		}	
		
		switch($modifier) {
			case '#gt#':
				$sql_where = "({$sfield} > ?)"; 
				$params = [$dates['end']];
				break;
			case '#gt=':
				$sql_where = "({$sfield} >= ?)"; 
				$params = [$dates['start']];
				break;
			case '#lt#':
				$sql_where = "({$efield} < ?)"; 
				$params = [$dates['start']];
				break;
			case '#lt=':
				$sql_where = "({$efield} <= ?)"; 
				$params = [$dates['end']];
				break;
			case '#eq#':
				$sql_where = "(({$sfield} BETWEEN ? AND ?) AND ({$efield} BETWEEN ? AND ?))"; 
				$params = [$dates['start'], $dates['end'], $dates['start'], $dates['end']];
				break;
			default:
				$sql_where = "(
						({$sfield} BETWEEN ? AND ?)
						OR
						({$efield} BETWEEN ? AND ?)
						OR
						({$sfield} <= ? AND {$efield} >= ?)	
					)";
				$params = [$dates['start'], $dates['end'], $dates['start'], $dates['end'], $dates['start'], $dates['end']];
				break;
		}
		
		$join_sql = $deleted_sql = '';
		if($t_subject->hasField('deleted')) {
			$t = $t_subject->tableName();
			$t_pk = $t_subject->primaryKey();
			$join_sql = " INNER JOIN {$t} AS t ON t.{$t_pk} = ca.row_id";
			$deleted_sql = " AND (t.deleted = 0)";
		}
		
		if($ap['type'] === 'INTRINSIC') {
			if($delete_sql) { $deleted_sql = " AND (deleted = 0)"; }
			$sql = "
				SELECT {$pk} row_id, 1 boost
				FROM {$table}
				WHERE
					{$sql_where}
					{$deleted_sql}
			";
		} else {
			$sql = "
				SELECT ca.row_id, 1 boost
				FROM ca_attribute_values cav
				INNER JOIN ca_attributes AS ca ON ca.attribute_id = cav.attribute_id
				{$join_sql}
				WHERE
					(cav.element_id = {$ap['element_info']['element_id']}) AND (ca.table_num = ?)
					AND
					{$sql_where}
					{$deleted_sql}
			";
		}
		
		return ['sql' => $sql, 'params' => [$params]];
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function parseModifier($text) {
		$modifier = null;
		if(preg_match("!^(#[gtleq]+[#=]{1})!i", $text, $m)) {
			$modifier = strtolower($m[1]);
			$text = preg_replace("!^(#[gtleq]+[#=]{1})!i", '', $text);
		}
		return [$text, $modifier];
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private function useSearchIndexForAP(array $ap) : bool {
		if(is_array($ap['indexing_options'])) {
			foreach(['INDEX_AS_IDNO', 'INDEX_ANCESTORS', 'CHILDREN_INHERIT', 'ANCESTORS_INHERIT', 'INDEX_AS_MIMETYPE'] as $k) {
				if(in_array($k, $ap['indexing_options'], true) || isset($ap['indexing_options'][$k])) { return true; }
			}
		}
		return false;
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	private static function getListsAsDict(mixed $lists) : ?array {
		if(!$lists) { return null; }
		if(!is_array($lists)) { $lists = [$lists]; }
		$key = join('-', $lists);
		if(CompositeCache::contains($key, 'SqlSearch2SearchExpansionDict')) {
			return CompositeCache::fetch($key, 'SqlSearch2SearchExpansionDict');
		} else {
			$t_list = new ca_lists();
			$dict = [];
			foreach($lists as $l) {
				$item_ids = $t_list->getItemsForList($l, ['idsOnly' => true]);
				if(sizeof($item_ids) > 0) {
					if($qr = caMakeSearchResult('ca_list_items', $item_ids)) {
						while($qr->nextHit()) {
							$pl = $qr->get('ca_list_items.preferred_labels', ['returnWithStructure' => true, 'returnAllLocales' => true]);
							$pl = array_shift($pl);
							$npl = $qr->get('ca_list_items.nonpreferred_labels', ['returnWithStructure' => true, 'returnAllLocales' => true]);
							$npl = array_shift($npl);
							
							foreach($pl as $locale_id => $by_id) {
								foreach($by_id as $id => $info) {
									$names = array_unique([mb_strtolower($info['name_singular']), mb_strtolower($info['name_plural'])]);
									
									foreach($npl as $nlocale_id => $nby_id) {
										foreach($nby_id as $nid => $ninfo) {
											foreach($names as $n) {
												$nnames = array_unique([mb_strtolower($ninfo['name_singular']), mb_strtolower($ninfo['name_plural'])]);
												foreach($nnames as $nn) {
													$dict[$nn][] = $n;
													$dict[$n][] = $nn;
												}
												foreach($nby_id as $nxinfo) {
													$nxnames = array_unique([mb_strtolower($nxinfo['name_singular']), mb_strtolower($nxinfo['name_plural'])]);
													foreach($nxnames as $nx) {
														$dict[$nx][] = $n;
														$dict[$nx][] = $nn;
													}
												}	
											}
										}
									}
								}
							}
						}
					}
				}
				foreach($dict as $n => $list) {
					$dict[$n] = array_unique($dict[$n]);
				}
				CompositeCache::save($key, $dict, 'SqlSearch2SearchExpansionDict');
				return $dict;
			}
		}
	}
	# -------------------------------------------------------
	# Magasin SQLite FTS5
	# -------------------------------------------------------
	/**
	 * Chemin du fichier d'index : <search_fts5_index_dir>/recherche.sqlite (app.conf ; défaut app/tmp).
	 * Refuse un répertoire sous la racine web qui ne soit pas sous app/ (protégé par .htaccess).
	 */
	private function _cheminIndex() : string {
		$dir = trim((string)$this->config->get('search_fts5_index_dir'));
		if(!strlen($dir)) { $dir = __CA_APP_DIR__.'/tmp'; }
		$dir = rtrim($dir, '/');
		if(!is_dir($dir) && !@mkdir($dir, 0775, true)) {
			throw new ApplicationException(_t('Fts5: index directory %1 does not exist and cannot be created', $dir));
		}
		if(!($real = realpath($dir))) {
			throw new ApplicationException(_t('Fts5: index directory %1 is not readable', $dir));
		}
		$base = defined('__CA_BASE_DIR__') ? realpath(__CA_BASE_DIR__) : false;
		$app = realpath(__CA_APP_DIR__);
		if($base && (strpos($real.'/', $base.'/') === 0) && !($app && (strpos($real.'/', $app.'/') === 0))) {
			throw new ApplicationException(_t('Fts5: index directory %1 is inside the web root; set search_fts5_index_dir outside it', $dir));
		}
		return $real.'/recherche.sqlite';
	}
	# -------------------------------------------------------
	/**
	 * Connexion à l'index (ouverte à la demande, schéma créé au besoin), partagée par chemin dans le processus.
	 */
	private function _bd() : PDO {
		if($this->pdo) { return $this->pdo; }
		$chemin = $this->_cheminIndex();
		if(isset(self::$connexions[$chemin])) { return $this->pdo = self::$connexions[$chemin]; }
		
		$neuf = !file_exists($chemin);
		try {
			$pdo = new PDO('sqlite:'.$chemin, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
			$pdo->exec('PRAGMA busy_timeout = 5000');
			$pdo->exec('PRAGMA journal_mode = WAL');
			$pdo->exec('PRAGMA synchronous = NORMAL');
			$pdo->exec('PRAGMA temp_store = MEMORY');
			$this->_creerSchema($pdo);
		} catch(PDOException $e) {
			throw new ApplicationException(_t('Fts5: cannot open search index %1: %2', $chemin, $e->getMessage()));
		}
		if($neuf) { @chmod($chemin, 0664); }	// lisible et inscriptible par le groupe (www-data / caUtils)
		
		return $this->pdo = self::$connexions[$chemin] = $pdo;
	}
	# -------------------------------------------------------
	/**
	 * Crée le schéma s'il manque ; un schéma d'une autre version est jeté (l'index se reconstruit).
	 */
	private function _creerSchema(PDO $pdo) : void {
		$existe = (int)$pdo->query("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = 'meta'")->fetchColumn();
		if($existe) {
			$version = (int)$pdo->query("SELECT valeur FROM meta WHERE cle = 'schema_version'")->fetchColumn();
			if($version === self::SCHEMA_VERSION) { return; }
			caLogEvent('WARN', "Fts5 : schéma d'index en version {$version}, attendu ".self::SCHEMA_VERSION." ; index recréé, reconstruction nécessaire", 'Fts5');
			foreach(['mots_vocab', 'mots', 'cles', 'meta'] as $t) { $pdo->exec("DROP TABLE IF EXISTS {$t}"); }
		}
		$pdo->exec('BEGIN IMMEDIATE');
		try {
			$pdo->exec("CREATE TABLE IF NOT EXISTS meta (cle TEXT PRIMARY KEY, valeur TEXT)");
			$pdo->exec("CREATE TABLE IF NOT EXISTS cles (
				id INTEGER PRIMARY KEY,
				table_num INTEGER NOT NULL,
				row_id INTEGER NOT NULL,
				field_table_num INTEGER NOT NULL,
				field_num TEXT NOT NULL,
				field_container_id INTEGER,
				field_row_id INTEGER NOT NULL,
				rel_type_id INTEGER NOT NULL DEFAULT 0,
				boost INTEGER NOT NULL DEFAULT 1,
				prive INTEGER NOT NULL DEFAULT 0,
				vide INTEGER NOT NULL DEFAULT 0
			)");
			$pdo->exec("CREATE INDEX IF NOT EXISTS i_cles_sujet ON cles (table_num, row_id, field_table_num, field_num, field_row_id)");
			$pdo->exec("CREATE INDEX IF NOT EXISTS i_cles_champ ON cles (field_table_num, field_num)");
			$pdo->exec("CREATE INDEX IF NOT EXISTS i_cles_contenu ON cles (field_table_num, field_row_id)");
			$pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS mots USING fts5 (txt, content = '', contentless_delete = 1, tokenize = 'unicode61 remove_diacritics 2', detail = full)");
			$pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS mots_vocab USING fts5vocab ('mots', 'row')");
			$pdo->exec("INSERT OR IGNORE INTO meta (cle, valeur) VALUES ('schema_version', ".self::SCHEMA_VERSION."), ('tokenizer', 'unicode61 remove_diacritics 2'), ('cree_le', datetime('now'))");
			$pdo->exec('COMMIT');
		} catch(PDOException $e) {
			$pdo->exec('ROLLBACK');
			throw $e;
		}
	}
	# -------------------------------------------------------
	/**
	 * Requêtes préparées d'insertion (une clé, un texte)
	 */
	private function _preparerInsertions(PDO $db) : void {
		if($this->st_ins_cle && $this->st_ins_mot) { return; }
		$this->st_ins_cle = $db->prepare("INSERT INTO cles (table_num, row_id, field_table_num, field_num, field_container_id, field_row_id, rel_type_id, boost, prive, vide) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
		$this->st_ins_mot = $db->prepare("INSERT INTO mots (rowid, txt) VALUES (?, ?)");
	}
	# -------------------------------------------------------
	/**
	 * Supprime des postings : dans le tampon (pas encore écrits), puis dans mots (par rowid, contentless_delete)
	 * et dans cles ; une transaction.
	 *
	 * @param array $lots Liste de critères (colonne de cles => valeur), tous requis (AND)
	 */
	private function _supprimerPostings(array $lots) : bool {
		if(!sizeof($lots)) { return true; }
		
		// Tampon : positions des colonnes dans une entrée (voir indexField)
		static $positions = ['table_num' => 0, 'row_id' => 1, 'field_table_num' => 2, 'field_num' => 3, 'field_row_id' => 5, 'rel_type_id' => 6];
		if(sizeof($this->doc_content_buffer)) {
			$this->doc_content_buffer = array_values(array_filter($this->doc_content_buffer, function($p) use ($lots, $positions) {
				foreach($lots as $criteres) {
					$correspond = true;
					foreach($criteres as $col => $val) {
						if((string)$p[$positions[$col]] !== (string)$val) { $correspond = false; break; }
					}
					if($correspond) { return false; }
				}
				return true;
			}));
		}
		
		$db = $this->_bd();
		$db->beginTransaction();
		try {
			foreach($lots as $criteres) {
				$where = join(' AND ', array_map(function($col) { return "({$col} = ?)"; }, array_keys($criteres)));
				$params = array_values($criteres);
				$st = $db->prepare("DELETE FROM mots WHERE rowid IN (SELECT id FROM cles WHERE {$where})");
				$st->execute($params);
				$st = $db->prepare("DELETE FROM cles WHERE {$where}");
				$st->execute($params);
			}
			$db->commit();
		} catch(PDOException $e) {
			if($db->inTransaction()) { $db->rollBack(); }
			throw new ApplicationException(_t('Fts5: cannot remove indexing: %1', $e->getMessage()));
		}
		return true;
	}
	# -------------------------------------------------------
	/**
	 * Interroge les postings.
	 *
	 * @param int $subject_tablenum
	 * @param string|null $match Expression FTS5 ; null = pas de contrainte sur les mots (valeurs vides / non vides)
	 * @param array $conditions Fragments SQL sur l'alias c (cles), avec des « ? »
	 * @param array $params Paramètres des fragments, dans l'ordre
	 * @param int|null $boost null = boost du posting, sinon constante
	 * @return array Hits au format de _arrayFromDbResult() : row_id => ['boost' => n, 'index_ids' => [...]]
	 */
	private function _rechercherPostings(int $subject_tablenum, ?string $match, array $conditions, array $params, ?int $boost=null) : array {
		$where = ['c.table_num = ?'];
		$p = [$subject_tablenum];
		if(!is_null($match)) {
			$where[] = 'c.id IN (SELECT rowid FROM mots WHERE mots MATCH ?)';
			$p[] = $match;
		}
		foreach($conditions as $c) { $where[] = "({$c})"; }
		$p = array_merge($p, $params);
		if($this->getOption('omitPrivateIndexing')) { $where[] = 'c.prive = 0'; }
		
		$sql_boost = is_null($boost) ? 'c.boost' : (int)$boost;
		try {
			$st = $this->_bd()->prepare("SELECT c.id index_id, c.row_id, {$sql_boost} boost FROM cles c WHERE ".join(' AND ', $where));
			$st->execute($p);
		} catch(PDOException $e) {
			caLogEvent('ERR', 'Fts5 : requête ['.$match.'] : '.$e->getMessage(), 'Fts5');
			return [];
		}
		return $this->_hitsDepuisLignes($st);
	}
	# -------------------------------------------------------
	/**
	 * Agrège les lignes (index_id, row_id, boost) en hits — pendant SQLite de _arrayFromDbResult()
	 */
	private function _hitsDepuisLignes(PDOStatement $st) : array {
		if(($max_index_count = (int)$this->search_config->get('search_result_description_maximum_index_matches')) < 1) {
			$max_index_count = 3;
		}
	 	$hits = [];
	 	while($row = $st->fetch(PDO::FETCH_ASSOC)) {
	 		$row_id = (int)$row['row_id'];
	 		if(!isset($hits[$row_id])) { 
	 			$hits[$row_id] = ['boost' => 0, 'index_ids' => []];
	 		}
	 		$hits[$row_id]['boost'] += (int)$row['boost'];
	 		
	 		if($this->get_result_desc_data && $row['index_id'] && (sizeof($hits[$row_id]['index_ids']) < $max_index_count)) {
	 			$hits[$row_id]['index_ids'][] = (int)$row['index_id'];
	 		}
	 	}
	 	return $hits;
	}
	# -------------------------------------------------------
	/**
	 * Expression MATCH pour un mot de requête (jokers et ancre compris) ; null si un joker ne correspond à aucun
	 * mot du vocabulaire ou si le mot n'a aucun caractère indexable. Le mot arrive tokenisé (minuscules,
	 * ponctuation retirée) ; un préfixe « mot* » est natif, les autres jokers passent par le vocabulaire.
	 */
	private function _expressionMatchMot(string $texte, ?string $ancre) : ?string {
		$texte = self::_normaliser($texte);
		if(!preg_match('/[\p{L}\p{N}]/u', $texte)) { return null; }
		$a_joker = ((strpos($texte, '*') !== false) || (strpos($texte, '?') !== false));
		$prefixe = false;
		
		if(!$a_joker) {
			$candidats = [$texte];
			if(($ancre === 'CONTAINS') && (bool)$this->search_config->get('use_substring_search_for_contains_searches')) {
				$candidats = $this->_termesDuVocabulaire('*'.self::_globEchapper($texte).'*');
				$ancre = null;
			} elseif(($ancre === 'START') && (bool)$this->search_config->get('add_wildcard_on_begins_searches')) {
				$prefixe = true;
			}
		} elseif(preg_match('!^([^*?]+)\*$!u', $texte, $m) && !in_array($ancre, ['EXACT', 'END'], true)) {
			$candidats = [$m[1]];
			$prefixe = true;
		} else {
			$candidats = $this->_termesDuVocabulaire(self::_globEchapper($texte, true));
		}
		if(!sizeof($candidats)) { return null; }
		
		$phrases = [];
		foreach($candidats as $c) { $phrases[] = $this->_phraseFts([$c], $ancre, $prefixe); }
		return (sizeof($phrases) > 1) ? '('.join(' OR ', $phrases).')' : $phrases[0];
	}
	# -------------------------------------------------------
	/**
	 * Phrase FTS5 : jetons entre guillemets doubles (guillemets internes doublés), sentinelles selon l'ancre,
	 * « * » final pour un préfixe.
	 */
	private function _phraseFts(array $mots, ?string $ancre, bool $prefixe) : string {
		if(in_array($ancre, ['EXACT', 'START'], true)) { array_unshift($mots, self::DEBUT); }
		if(in_array($ancre, ['EXACT', 'END'], true)) { $mots[] = self::FIN; }
		return '"'.str_replace('"', '""', join(' ', $mots)).'"'.($prefixe ? '*' : '');
	}
	# -------------------------------------------------------
	/**
	 * Termes du vocabulaire de l'index correspondant à un motif GLOB (ou à une condition SQL libre sur `term`),
	 * sentinelles exclues, plafonnés à MAX_TERMES_JOKER.
	 */
	private function _termesDuVocabulaire(?string $glob, ?string $condition=null, array $params=[]) : array {
		$where = ["term NOT IN ('".self::DEBUT."', '".self::FIN."')"];
		$p = [];
		if(!is_null($glob)) { $where[] = "term GLOB ?"; $p[] = self::_plierDiacritiques($glob); }
		if(!is_null($condition)) { $where[] = "({$condition})"; $p = array_merge($p, $params); }
		try {
			$st = $this->_bd()->prepare("SELECT term FROM mots_vocab WHERE ".join(' AND ', $where)." LIMIT ".(self::MAX_TERMES_JOKER + 1));
			$st->execute($p);
			$termes = $st->fetchAll(PDO::FETCH_COLUMN);
		} catch(PDOException $e) {
			caLogEvent('ERR', 'Fts5 : vocabulaire ['.$glob.'] : '.$e->getMessage(), 'Fts5');
			return [];
		}
		if(sizeof($termes) > self::MAX_TERMES_JOKER) {
			caLogEvent('WARN', 'Fts5 : joker ['.$glob.'] développé en plus de '.self::MAX_TERMES_JOKER.' termes ; liste tronquée', 'Fts5');
			$termes = array_slice($termes, 0, self::MAX_TERMES_JOKER);
		}
		return $termes;
	}
	# -------------------------------------------------------
	/**
	 * Restrictions restrictSearchToFields / excludeFieldsFromSearch en SQL sur l'alias c ; null si aucune.
	 *
	 * @return array|null [fragment SQL, paramètres]
	 */
	private function _sqlRestrictions(int $subject_tablenum) : ?array {
		if(!($restrictions = $this->_getFieldRestrictions($subject_tablenum))) { return null; }
		$res = [];
		$params = [];
		
		$res_by_table = [];
		foreach($restrictions['restrict'] as $r) {
			if(!is_array($r)) { continue; }
			$res_by_table[$r['table_num']][] = $r['field_num'];
		}
		foreach($res_by_table as $rtable_num => $rfield_nums) {
			$res[] = "(c.field_table_num = ? AND c.field_num IN (".self::_marques(sizeof($rfield_nums))."))";
			$params[] = (int)$rtable_num;
			foreach($rfield_nums as $f) { $params[] = (string)$f; }
		}
		
		$flds = [];
		foreach($restrictions['exclude'] as $r) {
			if(!is_array($r)) { continue; }
			$flds[] = $r['table_num'].'/'.$r['field_num'];
		}
		if(sizeof($flds)) {
			$res[] = "((c.field_table_num || '/' || c.field_num) NOT IN (".self::_marques(sizeof($flds))."))";
			$params = array_merge($params, $flds);
		}
		if(!sizeof($res)) { return null; }
		return ['('.join(' OR ', $res).')', $params];
	}
	# -------------------------------------------------------
	/**
	 * Texte d'un posting : mots normalisés entre sentinelles ; null si rien d'indexable.
	 */
	static private function _texteIndex(array $mots) : ?string {
		$mots = array_values(array_filter(array_map(function($m) { return self::_normaliser(trim((string)$m)); }, $mots), 'strlen'));
		if(!sizeof($mots)) { return null; }
		return self::DEBUT.' '.mb_scrub(join(' ', $mots), 'UTF-8').' '.self::FIN;
	}
	# -------------------------------------------------------
	/**
	 * Normalisation commune à l'indexation et à la requête : NFC puis ligatures (œ æ ß ĳ) ; minuscules et
	 * diacritiques sont l'affaire du tokenizer unicode61.
	 */
	static private function _normaliser(string $s) : string {
		if(class_exists('Normalizer') && !Normalizer::isNormalized($s, Normalizer::FORM_C)) {
			$s = Normalizer::normalize($s, Normalizer::FORM_C) ?: $s;
		}
		return strtr($s, ['œ' => 'oe', 'Œ' => 'oe', 'æ' => 'ae', 'Æ' => 'ae', 'ß' => 'ss', 'ĳ' => 'ij', 'Ĳ' => 'ij']);
	}
	# -------------------------------------------------------
	/**
	 * Pliage des diacritiques (NFD puis retrait des marques), pour comparer un motif GLOB au vocabulaire,
	 * qui est stocké plié par unicode61.
	 */
	static private function _plierDiacritiques(string $s) : string {
		if(class_exists('Normalizer') && ($d = Normalizer::normalize($s, Normalizer::FORM_D))) {
			$s = preg_replace('/\p{Mn}+/u', '', $d);
		}
		return mb_strtolower($s, 'UTF-8');
	}
	# -------------------------------------------------------
	/**
	 * Échappe un texte pour GLOB ; $jokers = true convertit * et ? de la requête en jokers GLOB (mêmes signes).
	 */
	static private function _globEchapper(string $s, bool $jokers=false) : string {
		$s = str_replace('[', '[[]', $s);
		if(!$jokers) { $s = str_replace(['*', '?'], ['[*]', '[?]'], $s); }
		return $s;
	}
	# -------------------------------------------------------
	/**
	 * n marques « ? » séparées par des virgules
	 */
	static private function _marques(int $n) : string {
		return join(',', array_fill(0, $n, '?'));
	}
	# -------------------------------------------------------
}
