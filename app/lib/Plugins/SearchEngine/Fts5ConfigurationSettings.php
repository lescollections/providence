<?php
/** ---------------------------------------------------------------------
 * app/lib/Plugins/SearchEngine/Fts5ConfigurationSettings.php :
 * contrôles de configuration du moteur SQLite FTS5 (écran « vérification de la configuration »)
 * ----------------------------------------------------------------------
 * CollectiveAccess — fork lescollections ; dérivé de SqlSearch2ConfigurationSettings.php (GPL v3).
 * ----------------------------------------------------------------------
 */
 
# ------------------------------------------------
define('__CA_FTS5_PDO_SQLITE__', 4101);
define('__CA_FTS5_FTS5_AVAILABLE__', 4102);
define('__CA_FTS5_INDEX_DIR_WRITABLE__', 4103);
define('__CA_FTS5_INTL_AVAILABLE__', 4104);
# ------------------------------------------------

require_once(__CA_LIB_DIR__.'/Datamodel.php');
require_once(__CA_LIB_DIR__.'/Search/SearchBase.php');
require_once(__CA_LIB_DIR__.'/Search/ASearchConfigurationSettings.php');
# ------------------------------------------------
class Fts5ConfigurationSettings extends ASearchConfigurationSettings {
	# ------------------------------------------------
	public function __construct(){
		parent::__construct();
	}
	# ------------------------------------------------
	public function getEngineName() {
		return "SQLite FTS5";
	}
	# ------------------------------------------------
	public function setSettings(){
		$this->opa_possible_errors = array(
			__CA_FTS5_PDO_SQLITE__, __CA_FTS5_FTS5_AVAILABLE__, __CA_FTS5_INDEX_DIR_WRITABLE__, __CA_FTS5_INTL_AVAILABLE__
		);
	}
	# ------------------------------------------------
	public function checkSetting($pn_setting_num){
		switch($pn_setting_num){
			case __CA_FTS5_PDO_SQLITE__:
				return $this->_checkPdoSqlite();
			case __CA_FTS5_FTS5_AVAILABLE__:
				return $this->_checkFts5();
			case __CA_FTS5_INDEX_DIR_WRITABLE__:
				return $this->_checkIndexDir();
			case __CA_FTS5_INTL_AVAILABLE__:
				return class_exists('Normalizer') ? __CA_SEARCH_CONFIG_OK__ : __CA_SEARCH_CONFIG_WARNING__;
			default:
				return false;
		}
	}
	# ------------------------------------------------
	public function getSettingName($pn_setting_num){
		switch($pn_setting_num){
			case __CA_FTS5_PDO_SQLITE__:
				return _t("PDO SQLite extension is available");
			case __CA_FTS5_FTS5_AVAILABLE__:
				return _t("SQLite has FTS5 with contentless delete (3.43 or later)");
			case __CA_FTS5_INDEX_DIR_WRITABLE__:
				return _t("Search index directory is writable");
			case __CA_FTS5_INTL_AVAILABLE__:
				return _t("PHP intl extension is available");
			default:
				return null;
		}
	}
	# ------------------------------------------------
	public function getSettingDescription($pn_setting_num){
		switch($pn_setting_num){
			case __CA_FTS5_PDO_SQLITE__:
				return _t("The Fts5 search engine stores its index in a SQLite file and needs the pdo_sqlite PHP extension.");
			case __CA_FTS5_FTS5_AVAILABLE__:
				return _t("The Fts5 search engine needs the FTS5 extension compiled into SQLite, in a version supporting contentless_delete (SQLite 3.43+).");
			case __CA_FTS5_INDEX_DIR_WRITABLE__:
				return _t("The index file is created in the directory set by search_fts5_index_dir in app.conf (default: app/tmp). It must be writable by the web server and by caUtils, and must not be inside the web root.");
			case __CA_FTS5_INTL_AVAILABLE__:
				return _t("The intl extension provides Unicode normalization (NFC) applied to indexed and searched text. Without it, accented text may be indexed inconsistently.");
			default:
				return null;
		}
	}
	# ------------------------------------------------
	public function getSettingHint($pn_setting_num){
		switch($pn_setting_num){
			case __CA_FTS5_PDO_SQLITE__:
				return _t("Install the pdo_sqlite PHP extension.");
			case __CA_FTS5_FTS5_AVAILABLE__:
				return _t("Upgrade the SQLite library used by PHP (Debian: libsqlite3-0 3.43 or later).");
			case __CA_FTS5_INDEX_DIR_WRITABLE__:
				return _t("Set search_fts5_index_dir in app.conf to a writable directory outside the web root, or fix its permissions.");
			case __CA_FTS5_INTL_AVAILABLE__:
				return _t("Install the intl PHP extension.");
			default:
				return null;
		}
	}
	# ------------------------------------------------
	private function _checkPdoSqlite(){
		return (extension_loaded('pdo_sqlite') && in_array('sqlite', PDO::getAvailableDrivers(), true)) ? __CA_SEARCH_CONFIG_OK__ : __CA_SEARCH_CONFIG_ERROR__;
	}
	# ------------------------------------------------
	private function _checkFts5(){
		if($this->_checkPdoSqlite() !== __CA_SEARCH_CONFIG_OK__) { return __CA_SEARCH_CONFIG_ERROR__; }
		try {
			$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
			$version = (string)$pdo->query("SELECT sqlite_version()")->fetchColumn();
			if(version_compare($version, '3.43.0', '<')) { return __CA_SEARCH_CONFIG_ERROR__; }
			$pdo->exec("CREATE VIRTUAL TABLE t USING fts5 (txt, content = '', contentless_delete = 1, tokenize = 'unicode61 remove_diacritics 2')");
			return __CA_SEARCH_CONFIG_OK__;
		} catch(Exception $e) {
			return __CA_SEARCH_CONFIG_ERROR__;
		}
	}
	# ------------------------------------------------
	private function _checkIndexDir(){
		$config = Configuration::load();
		$dir = trim((string)$config->get('search_fts5_index_dir'));
		if(!strlen($dir)) { $dir = __CA_APP_DIR__.'/tmp'; }
		if(!is_dir($dir) || !is_writable($dir)) { return __CA_SEARCH_CONFIG_ERROR__; }
		$fichier = rtrim($dir, '/').'/recherche.sqlite';
		if(file_exists($fichier) && !is_writable($fichier)) { return __CA_SEARCH_CONFIG_ERROR__; }
		return __CA_SEARCH_CONFIG_OK__;
	}
	# ------------------------------------------------
}
