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
    private $exit = false;
    private $lock_file;
    private $root;
    private $remove_lock = true;

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

        if ($this->remove_lock) {
            $this->removeLocks();
        }
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

        $id = $options->getOpt('id');

        if($id) {
            $this->index($id);
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
            $this->remove_lock = false;
            exit(1);
        }

        $this->root = $conf['datadir'] . (empty($this->namespace) ? '' : '/' . $this->namespace);

        // index the root directory
        $this->index_dir($this->root);

        // get a list of namespaces
        $ns_directories = glob($this->root . '/*', GLOB_ONLYDIR);

        foreach($ns_directories as $ns_dir) {

            // restart when memory usage exceeds 256M
            if(memory_get_usage() > (ONE_MEGABYTE * 384)) {
                $this->error('Memory almost full, exiting.');
                $this->exit = true;
            }

            // used to exit cleanly if ctrl+c is detected
            if($this->exit) {
                break;
            }

            // check for a progress file
            $progress_file = $ns_dir . DS . '.indexer_progress';
            $next_dir = (is_file($progress_file)) ? file_get_contents($progress_file) : '';

            // index the next directory
            $this->index_dir(empty($next_dir) ? $ns_dir : $next_dir);

            $ns_iterator = new RecursiveDirectoryIterator($ns_dir, RecursiveDirectoryIterator::SKIP_DOTS);
            $dir_iterator = new RecursiveIteratorIterator($ns_iterator,
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD);

            $found_current = empty($next_dir);
            if ((is_file($progress_file))) {
                unlink($progress_file);
            }

            foreach ($dir_iterator as $path => $dir) {

                // used to exit cleanly if ctrl+c is detected
                if($this->exit) {
                    break;
                }

                if ($dir->isDir() && (substr($path, 0, 1) != '.')) {

                    if ($found_current) {
                        // save the next directory and break out of for loop
                        file_put_contents($progress_file, $path);
                        break;
                    }

                    if ($path == $next_dir) {
                        $found_current = true;
                    }
                }
            }
        }
    }

    /**
     * Update the index
     * @param $dir
     */
    function index_dir($dir) {
        global $conf;

        $data = array();
        $this->quiet_echo("Searching pages... ");
        search($data, $dir, 'search_allpages', array('skipacl' => true, 'depth' => 1));
        $this->quiet_echo(count($data)." pages found.\n");

        $ns_prefix = substr($dir, strlen($conf['datadir']) + 1);
        if ($ns_prefix !== false) {
            $ns_prefix = str_replace(DIRECTORY_SEPARATOR, ':', $ns_prefix) . ':';
        }

        foreach($data as $val) {

            if($this->exit) {
                break;
            }

            if ($ns_prefix !== false) {
                $val['id'] = $ns_prefix . $val['id'];
            }
            $this->index($val['id']);
        }
    }

    /**
     * Index the given page
     *
     * @param string $id
     */
    function index($id) {
        $this->quiet_echo("$id... ");
        idx_addPage($id, !$this->quiet, $this->clear);
        $this->quiet_echo("done.\n");
    }

    /**
     * Clear all index files
     */
    private function clear_index() {
        $this->quiet_echo("Clearing index... ");
        idx_get_indexer()->clear();
        $this->quiet_echo("done.\n");
    }

    /**
     * Print message if not suppressed
     *
     * @param string $msg
     */
    function quiet_echo($msg) {
        if(!$this->quiet) echo $msg;
    }

    /**
     * Lock the indexer.
     */
    protected function lock() {
        global $conf;

        $this->lock_file = $conf['lockdir'] . '/_enhanced_indexer.lock';

        // clean up old stuff
        if (is_dir($this->lock_file)) {
           rmdir($this->lock_file);
        }

        // make sure the lock dir exists
        if (!is_dir($conf['lockdir'])) {
            @mkdir($conf['lockdir']);
        }

        // check if the lock file exists
        if (is_file($this->lock_file)) {

            // if it exists, is its process still running
            $old_pid = file_get_contents($this->lock_file);

            // if $pid is still running, $result will be 0 and count($output) will be 2.
            // also $output[1] will contain "indexer.php"
            exec("ps {$old_pid}", $output, $result);

            if (($result == 0)
                && (count($output) == 2)
                && (strpos($output[1], 'indexer.php') !== false))
            {
                // a previous instance of this script is still running
                return false;
            }
            else {
                unlink($this->lock_file);
            }
        }

        file_put_contents($this->lock_file, getmypid());

        return true;
    }

    public function removeLocks() {

        $this->quiet_echo('clearing lock...');
        $return = true;

        // attempt do delete the lock file
        if (is_file($this->lock_file)) {
            unlink($this->lock_file);
        }

        if (is_file($this->lock_file)) {
            $this->error('failed to remove ' . $this->lock_file . ', something is wrong');
            $return = false;
        }

        $this->quiet_echo("done\n");

        return $return;
    }

    public function sigInt() {
        $this->exit = true;
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
