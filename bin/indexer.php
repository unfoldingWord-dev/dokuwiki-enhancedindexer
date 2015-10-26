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
 *   -f, --force              Force the index rebuilding, skip date check.
 *   -i <s>, --id <s>         Only update specific id.
 *   -r <n>, --max-runs <n>   Restart after indexing n items.
 *   -n <s>, --namespace <s>  Only update items in namespace.
 *   -q, --quiet              Don't produce any output.
 *   -s <n>, --start <n>      Start at offset.
 *   --no-colors              Don't use any colors in output. Useful when piping output to other tools or files.
 *   -t <s> --temp-file <s>   Existing temp file to use.
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
    private $force = false;
    private $namespace = '';
    private $removeLocks = false;
    private $exit = false;
    private $clean = true;
    private $maxRuns = 0;
    private $startOffset = 0;

    /**
     * @var SplFileObject
     */
    private $temp_file;

    // save the list of files to be indexed in this file instead of an array to reduce memory consumption
    private static $tempFileName;
    private static $totalPagesToIndex = 0;

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
            'force',
            'force the index rebuilding, skip date check',
            'f'
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

        $options->registerOption(
            'start',
            'start at offset',
            's',
            true
        );

        $options->registerOption(
            'temp-file',
            'Existing temp file to use.',
            't',
            true
        );
    }

    public function __destruct() {
        $this->cleanup();
    }

    private function cleanup() {
        if($this->clean == false) {
            $this->quietecho('Saving Indexes...');
            enhanced_idx_get_indexer()->flushIndexes();
            $this->quietecho("done\n");
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
        $this->clear        = $options->getOpt('clear');
        $this->quiet        = $options->getOpt('quiet');
        $this->force        = $options->getOpt('force');
        $this->namespace    = $options->getOpt('namespace', '');
        $this->removeLocks  = $options->getOpt('remove-locks', '');
        $this->maxRuns      = $options->getOpt('max-runs', 0);
        $this->startOffset  = $options->getOpt('start', 0);
        self::$tempFileName = $options->getOpt('temp-file', '');

        $id = $options->getOpt('id');

        if($this->removeLocks) {
            $this->removeLocks();
        }

        if($id) {
            $this->index($id, 1, 1);
            $this->quietecho("done\n");
        } else {

            if($this->clear) {
                $this->clearindex();
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
            $dir      = $conf['datadir'] . DS . str_replace(':', DS, $this->namespace);
            $idPrefix = $this->namespace . ':';
        } else {
            $dir      = $conf['datadir'];
            $idPrefix = '';
        }

        // get a temp file name to store the list of files to index
        if(empty(self::$tempFileName)) {

            self::$tempFileName = sys_get_temp_dir() . '/EnhancedIndexer-' . microtime(true);
            if(file_exists(self::$tempFileName)) {
                self::$tempFileName .= 'b';
            }

            $this->quietecho("Searching pages... ");

            // we aren't going to use $data, but the search function needs it
            $data = array();
            search($data, $dir, 'EnhancedIndexerCLI::save_search_allpages', array('skipacl' => true));
            $this->quietecho(self::$totalPagesToIndex . " pages found.\n");
        }
        else {

            // this is a restart, count the lines in the existing temp file
            $this->quietecho("Finding last position... ");
            self::$totalPagesToIndex = self::get_line_count(self::$tempFileName);
        }

        $cnt = 0;

        try {

            // we are using the SplFileObject so we can read one line without loading the whole file
            $this->temp_file = new SplFileObject(self::$tempFileName);

            // this flag tells the SplFileObject to remove the \n from the end of each line it reads
            $this->temp_file->setFlags(SplFileObject::DROP_NEW_LINE);

            for($i = $this->startOffset; $i < self::$totalPagesToIndex; $i++) {

                // make sure the file handle is still open
                if(!$this->temp_file->valid()) {
                    break;
                }

                // move to the next line and read the page id
                $this->temp_file->seek($i);
                $pageId = $this->temp_file->current();

                // index this page, if not done already
                if(($this->index($idPrefix . $pageId, $i + 1, self::$totalPagesToIndex))) {
                    $cnt++;
                    $this->clean = false;
                }

                // used to exit cleanly if ctrl+c is detected
                if($this->exit) {
                    break;
                }

                // restart when memory usage exceeds 256M
                if(memory_get_usage() > (ONE_MEGABYTE * 256)) {
                    $this->error('Memory almost full, resetting');
                    $this->restart($i + 1);
                }

                if($this->maxRuns && $cnt >= $this->maxRuns) {
                    $this->error('Max runs reached ' . $cnt . ', restarting');
                    $this->restart($i + 1);
                }
            }

            // release the temp file
            if(!empty($this->temp_file)) {
                $this->temp_file = null;
                unset($this->temp_file);
            }
        } catch(Exception $e) {
            $this->error("\n" . $e->getMessage());
        }

        // remove the temp file
        if(is_file(self::$tempFileName)) {
            $this->quietecho("Removing temp file... ");
            unlink(self::$tempFileName);
            $this->quietecho("done\n");
        }
    }

    function restart($start = 0) {
        global $argv;
        $this->cleanup();
        $args = $argv;
        array_unshift($args, '-d', 'memory_limit=' . ini_get('memory_limit'));

        foreach($args as $key => $arg) {
            if($arg == '--clear' || $arg == '-c') {
                $args[$key] = '--force';
            }
        }

        array_push($args, '--start', $start);
        array_push($args, '--temp-file', self::$tempFileName);

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
     * @param        $position
     * @param        $total
     * @return bool
     */
    function index($id, $position, $total) {
        $this->quietecho("{$position} of {$total}: {$id}... ");
        return enhanced_idx_addPage($id, !$this->quiet, $this->force || $this->clear);
    }

    /**
     * Clear all index files
     */
    function clearindex() {
        $this->quietecho("Clearing index... ");
        enhanced_idx_get_indexer()->clear();
        $this->quietecho("done\n");
    }

    /**
     * Print message if not supressed
     *
     * @param string $msg
     */
    function quietecho($msg) {
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

        $this->quietecho('clearing lock...');
        $return = true;

        if(is_dir($conf['lockdir'] . '/_enhanced_indexer.lock') && !rmdir($conf['lockdir'] . '/_enhanced_indexer.lock')) {
            $this->error('failed to remove ' . $conf['lockdir'] . '/_enhanced_indexer.lock something is wrong');
            $return = false;
        }

        if(is_dir($conf['lockdir'] . '/_indexer.lock') && !rmdir($conf['lockdir'] . '/_indexer.lock')) {
            $this->error('failed to remove ' . $conf['lockdir'] . '/_indexer.lock something is wrong');
            $return = false;
        }
        $this->quietecho("done\n");

        return $return;
    }

    public function sigInt() {
        $this->exit = true;
    }

    /**
     * Just lists all documents
     *
     * $opts['depth']   recursion level, 0 for all
     * $opts['hash']    do md5 sum of content?
     * $opts['skipacl'] list everything regardless of ACL
     *
     * @param $data
     * @param $base
     * @param $file
     * @param $type
     * @param $lvl
     * @param $opts
     * @return bool
     */
    public static function save_search_allpages(/** @noinspection PhpUnusedParameterInspection */
        &$data, $base, $file, $type, $lvl, $opts) {

        if(!empty($opts['depth'])) {
            $parts = explode('/', ltrim($file, '/'));
            if(($type == 'd' && count($parts) >= $opts['depth'])
                || ($type != 'd' && count($parts) > $opts['depth'])
            ) {
                return false; // depth reached
            }
        }

        //we do nothing with directories
        if($type == 'd') {
            return true;
        }

        //only search txt files
        if(substr($file, -4) != '.txt') {
            return true;
        }

        $pathId = pathID($file);

        if(!$opts['skipacl'] && auth_quickaclcheck($pathId) < AUTH_READ) {
            return false;
        }

        file_put_contents(self::$tempFileName, "$pathId\n", FILE_APPEND);
        self::$totalPagesToIndex++;

        return true;
    }

    /**
     * This is ugly, but it seems to be the best way to get the number of lines in a file
     * @param $file_name
     * @return int
     */
    private static function get_line_count($file_name) {

        $line_count = 0;
        $handle = fopen($file_name, "r");
        while(!feof($handle)){
            /** @noinspection PhpUnusedLocalVariableInspection */
            $line = fgets($handle);
            $line_count++;
        }

        fclose($handle);

        return $line_count;
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
