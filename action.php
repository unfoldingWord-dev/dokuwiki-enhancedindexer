<?php
/**
 * Popularity Feedback Plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

require_once(DOKU_PLUGIN.'action.php');

class action_plugin_enhancedindexer extends Dokuwiki_Action_Plugin {

    /**
     * Register its handlers with the dokuwiki's event controller
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('INDEXER_TASKS_RUN', 'BEFORE',  $this, 'preventDefaultIndexer', array());
    }

    /**
     * Runs the the default function from lib/exe/indexer.php except runIndexer()
     * This lets the cron job of enhancedindexer do all the work for indexing to prevent locks
     * 
     * @param Doku_Event $event
     * @param array $param
     */
    public function preventDefaultIndexer(Doku_Event &$event, $param) {
        runSitemapper() or
        sendDigest() or
        runTrimRecentChanges() or
        runTrimRecentChanges(true) or
        $event->advise_after();
        $event->preventDefault();
    }

}
