#!/usr/bin/php
<?php
/**
 * Usage:  indexer.php <options>
 *
 * Updates the search index by indexing all new or changed pages.
 * When the -c option is given the index is cleared first.
 *
 * Options:
 *   -h, --help               Display this help screen and exit immediately.
 *   -c, --clear              Clear the index before updating.
 *   -n <s>, --namespace <s>  Only update items in namespace.
 *   -i <s>, --id <s>         Only update specific id.
 *   -q, --quiet              Don't produce any output.
 *   -r <n>, --max-runs <n>   Restart after indexing n items. [not used]
 *   --no-colors              Don't use any colors in output. Useful when piping output to other tools or files.
 */

if(!defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__) . '/../../../../') . '/');
if(!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
define('NOSESSION', 1);
if(!defined('ONE_MEGABYTE')) define('ONE_MEGABYTE', 1048576);

/** @noinspection PhpIncludeInspection */
require_once(DOKU_INC . 'inc/init.php');

if(class_exists('DokuCLI') == false) {
    /** @noinspection PhpIncludeInspection */
    require_once(DOKU_INC . 'inc/cli.php');
}

require_once(dirname(dirname(__FILE__)) . '/inc/Doku_Indexer_Enhanced.php');

/**
 * Converts memory size string into number of bytes
 * @param $size_str
 * @return int
 */
function return_bytes($size_str) {
    switch(substr($size_str, -1)) {
        case 'K':
        case 'k':
            return (int) $size_str * 1024;
        case 'M':
        case 'm':
            return (int) $size_str * ONE_MEGABYTE;
        case 'G':
        case 'g':
            return (int) $size_str * ONE_MEGABYTE * 1024;
        default:
            if(is_numeric($size_str)) {
                return (int) $size_str;
            } else {
                return $size_str;
            }
    }
}

// if memory limit is not set, or is less than 512MB, increase it to 512MB
if(return_bytes(ini_get('memory_limit')) < (ONE_MEGABYTE * 512)) {
    ini_set('memory_limit', '512M');
}


/**
 * Update the Search Index from command line
 */
class EnhancedIndexerCLI extends DokuCLI {

    private $quiet = false;
    private $clear = false;
    private $namespace = '';
    private $removeLocks = false;
    private $exit = false;
    private $clean = true;
    private $maxRuns = 0;
    private $totalPagesIndexed = 1;


    /**
     * Register options and arguments on the given $options object
     *
     * @param DokuCLI_Options $options
     * @return void
     */
    protected function setup(DokuCLI_Options $options) {
        $options->setHelp(
            'Updates the search index by indexing all new or changed pages. When the -c option is ' .
            'given the index is cleared first.'
        );

        $options->registerOption(
            'clear',
            'clear the index before updating',
            'c'
        );

        $options->registerOption(
            'namespace',
            'Only update items in namespace',
            'n',
            true // needs arg
        );

        $options->registerOption(
            'quiet',
            'don\'t produce any output',
            'q'
        );

        $options->registerOption(
            'id',
            'only update specific id',
            'i',
            true // needs arg
        );

        $options->registerOption(
            'remove-locks',
            'remove any locks on the indexer',
            'l'
        );

        $options->registerOption(
            'max-runs',
            'Restart after indexing n items',
            'r',
            true
        );
    }

    public function __destruct() {
        $this->cleanup();
    }

    private function cleanup() {
        if($this->clean == false) {
            $this->quiet_echo('Saving Indexes...');
            enhanced_idx_get_indexer()->flushIndexes();
            $this->quiet_echo("done\n");
            $this->clean = true;
        }

        // release the temp file
        if(!empty($this->temp_file)) {
            $this->temp_file = null;
            unset($this->temp_file);
        }

        $this->removeLocks();
    }

    /**
     * Your main program
     *
     * Arguments and options have been parsed when this is run
     *
     * @param DokuCLI_Options $options
     * @return void
     */
    protected function main(DokuCLI_Options $options) {
        $this->clear       = $options->getOpt('clear');
        $this->quiet       = $options->getOpt('quiet');
        $this->namespace   = $options->getOpt('namespace', '');
        $this->removeLocks = $options->getOpt('remove-locks', '');
        $this->maxRuns     = $options->getOpt('max-runs', 10000);

        $id = $options->getOpt('id');

        if($this->removeLocks) {
            $this->removeLocks();
        }

        if($id) {
            $this->index($id, $this->wikiFN($id));
            $this->quiet_echo("done\n");
        } else {

            if($this->clear) {
                $this->clear_index();
            }

            $this->update();
        }
    }

    /**
     * Update the index
     */
    function update() {
        global $conf;

        // using a lock to prevent the indexer from running multiple instances
        if($this->lock() == false) {
            $this->error('unable to get lock, bailing');
            exit(1);
        }

        // are we indexing a single namespace or all files?
        if($this->namespace) {
            $dir = $conf['datadir'] . DS . str_replace(':', DS, $this->namespace);
        }
        else {
            $dir = $conf['datadir'];
        }

        $offset = strlen($dir) + 1;

        try {

            $dir_iterator = new RecursiveDirectoryIterator($dir);
            $file_iterator = new RecursiveIteratorIterator($dir_iterator);

            /** @var SplFileInfo $file */
            foreach($file_iterator as $file) {

                if(substr($file->getFilename(), -4) !== '.txt') continue;

                // used to exit cleanly if ctrl+c is detected
                if($this->exit) {
                    break;
                }

                $fullFileName = $file->getPathname();

                $pathId = pathID(substr($fullFileName, $offset));

                // skip if already indexed
                if($this->needs_indexed($pathId, $fullFileName)) {
                    $this->index($pathId, $fullFileName);
                    $this->totalPagesIndexed++;
                }

                // restart if the maxRuns number of pages have been indexed
                if (!empty($this->maxRuns) && $this->totalPagesIndexed > $this->maxRuns) {
                    $this->restart();
                    break;
                }
            }
        }
        catch(Exception $e) {
            $this->error("\n" . $e->getMessage());
        }
    }

    function restart() {
        global $argv;
        $this->cleanup();
        $args = $argv;
        array_unshift($args, '-d', 'memory_limit=' . ini_get('memory_limit'));

        foreach($args as $key => $arg) {
            if($arg == '--clear' || $arg == '-c') {
                unset($args[$key]);
                break;
            }
        }

        if (!empty($this->namespace)) {
            array_push($args, '-n', $this->namespace);
        }

        // for running in a debugger
        if(empty($_SERVER['_'])) {
            pcntl_exec('/usr/bin/php', $args);
        } else {
            pcntl_exec($_SERVER['_'], $args);
        }
    }

    /**
     * Index the given page
     *
     * @param string $id
     * @param string $fileName
     * @return bool
     */
    private function index($id, $fileName) {

        if($this->namespace) {
            $id = $this->namespace . ':' . $id;
        }

        $this->quiet_echo("{$this->totalPagesIndexed}: {$id}... ");
        return $this->enhanced_idx_addPage($id, !$this->quiet, $fileName);
    }

    /**
     * Clear all index files
     */
    private function clear_index() {
        global $conf;

        $this->quiet_echo("Clearing index... ");
        enhanced_idx_get_indexer()->clear();

        // remove the *.indexed files for the pages
        $meta_dir = $conf['metadir'];

        if(!empty($this->namespace)) {
            $meta_dir .= DS . $this->namespace;
        }

        $dir_iterator = new RecursiveDirectoryIterator($meta_dir);
        $file_iterator = new RecursiveIteratorIterator($dir_iterator);

        foreach($file_iterator as $file) {

            if(substr($file->getFilename(), -8) !== '.indexed') continue;

            unlink($file->getPathname());
        }

        $this->quiet_echo("done\n");
    }

    /**
     * Print message if not supressed
     *
     * @param string $msg
     */
    private function quiet_echo($msg) {
        if(!$this->quiet) {
            echo $msg;
        }
    }

    /**
     * Lock the indexer.
     */
    protected function lock() {
        global $conf;

        $lock = $conf['lockdir'] . '/_enhanced_indexer.lock';
        if(!@mkdir($lock, $conf['dmode'])) {
            if(is_dir($lock) && $this->removeLocks) {
                // looks like a stale lock - remove it
                if(!@rmdir($lock)) {
                    // removing the stale lock failed
                    return false;
                } else {
                    // stale lock removed
                    return true;
                }
            } else {
                return false;
            }
        }
        if(!empty($conf['dperm'])) {
            chmod($lock, $conf['dperm']);
        }
        return true;
    }

    public function removeLocks() {
        global $conf;

        $this->quiet_echo('clearing lock...');
        $return = true;

        if(is_dir($conf['lockdir'] . '/_enhanced_indexer.lock') && !rmdir($conf['lockdir'] . '/_enhanced_indexer.lock')) {
            $this->error('failed to remove ' . $conf['lockdir'] . '/_enhanced_indexer.lock something is wrong');
            $return = false;
        }

        if(is_dir($conf['lockdir'] . '/_indexer.lock') && !rmdir($conf['lockdir'] . '/_indexer.lock')) {
            $this->error('failed to remove ' . $conf['lockdir'] . '/_indexer.lock something is wrong');
            $return = false;
        }
        $this->quiet_echo("done\n");

        return $return;
    }

    public function sigInt() {
        $this->exit = true;
    }

    private function needs_indexed($pageId, $pageFile) {

        if(empty($this->namespace)) {
            $idx_tag = $this->metaFN($pageId,'.indexed');
        }
        else {
            $idx_tag = $this->metaFN($this->namespace . ':' . $pageId,'.indexed');
        }

        if(trim(io_readFile($idx_tag)) == idx_get_version()){
            $last = @filemtime($idx_tag);
            if($last > @filemtime($pageFile)){
                return false;
            }
        }

        return true;
    }

    /**
     * returns the full path to the meta file specified by ID and extension
     *
     * @author Steven Danz <steven-danz@kc.rr.com>
     *
     * @param string $id   page id
     * @param string $ext  file extension
     * @return string full path
     */
    function metaFN($id, $ext){
        global $conf;
        $id = $this->cleanID($id);
        $id = str_replace(':','/',$id);
        $fn = $conf['metadir'].'/'.utf8_encodeFN($id).$ext;
        return $fn;
    }

    /**
     * Remove unwanted chars from ID
     *
     * Cleans a given ID to only use allowed characters. Accented characters are
     * converted to unaccented ones
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     *
     * @param  string  $raw_id    The pageid to clean
     * @param  boolean $ascii     Force ASCII
     * @return string cleaned id
     */
    function cleanID($raw_id,$ascii=false){
        global $conf;
        static $sepcharpat = null;

        $sepchar = $conf['sepchar'];
        if($sepcharpat == null) // build string only once to save clock cycles
            $sepcharpat = '#\\'.$sepchar.'+#';

        $id = trim((string)$raw_id);
        $id = utf8_strtolower($id);

        //alternative namespace seperator
        if($conf['useslash']){
            $id = strtr($id,';/','::');
        }else{
            $id = strtr($id,';/',':'.$sepchar);
        }

        if($conf['deaccent'] == 2 || $ascii) $id = utf8_romanize($id);
        if($conf['deaccent'] || $ascii) $id = utf8_deaccent($id,-1);

        //remove specials
        $id = utf8_stripspecials($id,$sepchar,'\*');

        if($ascii) $id = utf8_strip($id);

        //clean up
        $id = preg_replace($sepcharpat,$sepchar,$id);
        $id = preg_replace('#:+#',':',$id);
        $id = trim($id,':._-');
        $id = preg_replace('#:[:\._\-]+#',':',$id);
        $id = preg_replace('#[:\._\-]+:#',':',$id);

        return($id);
    }

    /**
     * Adds/updates the search index for the given page
     *
     * Locking is handled internally.
     *
     * @param string  $page    name of the page to index
     * @param boolean $verbose print status messages
     * @param         $fileName
     * @return bool the function completed successfully
     * @author Tom N Harris <tnharris@whoopdedo.org>
     */
    function enhanced_idx_addPage($page, $verbose=false, $fileName) {

        $idx_file = $this->metaFN($page,'.indexed');

        $indexenabled = $this->p_get_metadata($page, 'internal index', $fileName);
        if ($indexenabled === false) {
            $result = false;
            if (@file_exists($idx_file)) {
                $Indexer = enhanced_idx_get_indexer();
                $result = $Indexer->deletePage($page);
                if ($result === "locked") {
                    if ($verbose) print("Indexer: locked".DOKU_LF);
                    return false;
                }
                @unlink($idx_file);
            }
            if ($verbose) print("Indexer: index disabled for $page".DOKU_LF);
            return $result;
        }

        $Indexer = enhanced_idx_get_indexer();
        $pid = $Indexer->getPID($page);
        if ($pid === false) {
            if ($verbose) print("Indexer: getting the PID failed for $page".DOKU_LF);
            return false;
        }
        $body = '';
        $metadata = array();
        $metadata['title'] = p_get_metadata($page, 'title', METADATA_RENDER_UNLIMITED);
        if (($references = p_get_metadata($page, 'relation references', METADATA_RENDER_UNLIMITED)) !== null)
            $metadata['relation_references'] = array_keys($references);
        else
            $metadata['relation_references'] = array();

        if (($media = p_get_metadata($page, 'relation media', METADATA_RENDER_UNLIMITED)) !== null)
            $metadata['relation_media'] = array_keys($media);
        else
            $metadata['relation_media'] = array();

        $data = compact('page', 'body', 'metadata', 'pid');
        $evt = new Doku_Event('INDEXER_PAGE_ADD', $data);
        if ($evt->advise_before()) $data['body'] = $data['body'] . " " . rawWiki($page);
        $evt->advise_after();
        unset($evt);
        extract($data);

        $result = $Indexer->addPageWords($page, $body);
        if ($result === "locked") {
            if ($verbose) print("Indexer: locked".DOKU_LF);
            return false;
        }

        if ($result) {
            $result = $Indexer->addMetaKeys($page, $metadata);
            if ($result === "locked") {
                if ($verbose) print("Indexer: locked".DOKU_LF);
                return false;
            }
        }

        if ($result) {
            io_saveFile($this->metaFN($page,'.indexed'), idx_get_version());
        }

        if ($verbose) {
            print("Indexer: finished".DOKU_LF);
        }

        return $result;
    }

    /**
     * returns the metadata of a page
     *
     * @param string $id     The id of the page the metadata should be returned from
     * @param string $key    The key of the metdata value that shall be read (by default everything) - separate hierarchies by " " like "date created"
     * @param        $pageFileName
     * @return mixed The requested metadata fields
     *
     * @author Esther Brunner <esther@kaffeehaus.ch>
     * @author Michael Hamann <michael@content-space.de>
     */
    function p_get_metadata($id, $key='', $pageFileName){

        $metaFileName = $this->metaFN($id, '.meta');
        $meta = $this->p_read_metadata($metaFileName);

        if (!file_exists($metaFileName) || @filemtime($pageFileName) > @filemtime($metaFileName)) {

            $old_meta = $meta;
            $meta = $this->p_render_metadata($id, $meta, $pageFileName);

            // only update the file when the metadata has been changed
            if ($meta != $old_meta) {
                $ableToSave = io_saveFile($metaFileName, serialize($meta));

                if (!$ableToSave) {
                    $this->quiet_echo('Unable to save metadata file. Hint: disk full; file permissions; safe_mode setting.');
                }
            }
        }

        $val = $meta['current'];

        // filter by $key
        foreach(preg_split('/\s+/', $key, 2, PREG_SPLIT_NO_EMPTY) as $cur_key) {
            if (!isset($val[$cur_key])) {
                return null;
            }
            $val = $val[$cur_key];
        }
        return $val;
    }

    /**
     * read the metadata from source/cache for $id
     * (internal use only - called by p_get_metadata & p_set_metadata)
     *
     * @author   Christopher Smith <chris@jalakai.co.uk>
     *
     * @param $fileName
     * @return array metadata
     *
     */
    function p_read_metadata($fileName) {

        $meta = file_exists($fileName) ? unserialize(io_readFile($fileName, false)) : array('current'=>array(),'persistent'=>array());

        return $meta;
    }

    /**
     * returns the full path to the datafile specified by ID and optional revision
     *
     * The filename is URL encoded to protect Unicode chars
     *
     * @param  $raw_id  string   id of wikipage
     * @param  $rev     int|string   page revision, empty string for current
     * @param  $clean   bool     flag indicating that $raw_id should be cleaned.  Only set to false
     *                           when $id is guaranteed to have been cleaned already.
     * @return string full path
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function wikiFN($raw_id,$rev='',$clean=true){
        global $conf;

        $id = $raw_id;

        if ($clean) $id = $this->cleanID($id);
        $id = str_replace(':','/',$id);
        if(empty($rev)){
            $fn = $conf['datadir'].'/'.utf8_encodeFN($id).'.txt';
        }else{
            $fn = $conf['olddir'].'/'.utf8_encodeFN($id).'.'.$rev.'.txt';
            if($conf['compression']){
                //test for extensions here, we want to read both compressions
                if (file_exists($fn . '.gz')){
                    $fn .= '.gz';
                }else if(file_exists($fn . '.bz2')){
                    $fn .= '.bz2';
                }else{
                    //file doesnt exist yet, so we take the configured extension
                    $fn .= '.' . $conf['compression'];
                }
            }
        }

        return $fn;
    }

    /**
     * renders the metadata of a page
     *
     * @author Esther Brunner <esther@kaffeehaus.ch>
     *
     * @param string $id   page id
     * @param array  $orig the original metadata
     * @param        $pageFileName
     * @return array|null array('current'=> array,'persistent'=> array);
     */
    function p_render_metadata($id, $orig, $pageFileName){
        // make sure the correct ID is in global ID
        global $ID;

        $keep = $ID;
        $ID   = $id;

        // add an extra key for the event - to tell event handlers the page whose metadata this is
        $orig['page'] = $id;
        $evt = new Doku_Event('PARSER_METADATA_RENDER', $orig);
        if ($evt->advise_before()) {

            // get instructions
            $instructions = p_cached_instructions($pageFileName,false,$id);
            if(is_null($instructions)){
                $ID = $keep;
                return null; // something went wrong with the instructions
            }

            // set up the renderer
            $renderer = new Doku_Renderer_metadata();
            $renderer->meta =& $orig['current'];
            $renderer->persistent =& $orig['persistent'];

            // loop through the instructions
            foreach ($instructions as $instruction){
                // execute the callback against the renderer
                call_user_func_array(array(&$renderer, $instruction[0]), (array) $instruction[1]);
            }

            $evt->result = array('current'=>&$renderer->meta,'persistent'=>&$renderer->persistent);
        }
        $evt->advise_after();

        // clean up
        $ID = $keep;
        return $evt->result;
    }

}

// Main
$cli = new EnhancedIndexerCLI();

if(function_exists('pcntl_signal')) {
    // ensure things exit cleanly with ctrl+c
    declare(ticks = 10);

    pcntl_signal(SIGINT, array($cli, 'sigInt'));
    pcntl_signal(SIGTERM, array($cli, 'sigInt'));
}

$conf['cachetime'] = 60 * 60; // default is -1 which means cache isn't used :(

$cli->run();
