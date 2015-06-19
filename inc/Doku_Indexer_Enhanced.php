<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of Doku_Indexer_Enhanced
 *
 * @author david
 */
class Doku_Indexer_Enhanced extends Doku_Indexer {
    
    protected $indexes = array();
    protected $indexesToFlush = array();


    /**
     * Retrieve the entire index.
     *
     * The $suffix argument is for an index that is split into
     * multiple parts. Different index files should use different
     * base names.
     *
     * @param string    $idx    name of the index
     * @param string    $suffix subpart identifier
     * @return array            list of lines without CR or LF
     * @author Tom N Harris <tnharris@whoopdedo.org>
     */
    protected function getIndex($idx, $suffix) {
        
        if(isset($this->indexes[$idx.$suffix]) === false) {
            $this->indexes[$idx.$suffix] = parent::getIndex($idx, $suffix);
        }
        
        return $this->indexes[$idx.$suffix];
    }

    /**
     * Replace the contents of the index with an array.
     *
     * @param string    $idx    name of the index
     * @param string    $suffix subpart identifier
     * @param array     $lines  list of lines without LF
     * @return bool             If saving succeeded
     * @author Tom N Harris <tnharris@whoopdedo.org>
     */
    protected function saveIndex($idx, $suffix, $lines) {
        $key = $idx.$suffix;
        $this->indexes[$key] = $lines;
        if(isset($this->indexesToFlush[$key]) === false) {
            $this->indexesToFlush[$idx.$suffix] = array($idx,$suffix);
        }
        
        return true;
    }

    /**
     * Retrieve a line from the index.
     *
     * @param string    $idx    name of the index
     * @param string    $suffix subpart identifier
     * @param int       $id     the line number
     * @return string           a line with trailing whitespace removed
     * @author Tom N Harris <tnharris@whoopdedo.org>
     */
    protected function getIndexKey($idx, $suffix, $id) {
        
        $index = $this->getIndex($idx, $suffix);
        return $index[$id];
    }

    /**
     * Write a line into the index.
     *
     * @param string    $idx    name of the index
     * @param string    $suffix subpart identifier
     * @param int       $id     the line number
     * @param string    $line   line to write
     * @return bool             If saving succeeded
     * @author Tom N Harris <tnharris@whoopdedo.org>
     */
    protected function saveIndexKey($idx, $suffix, $id, $line) {
        $key = $idx.$suffix;
        if(isset($this->indexes[$key]) === false) {
            $this->indexes[$key] = parent::getIndex($idx, $suffix);
        }
        
        $this->indexes[$key][$id] = $line;
                
        if(isset($this->indexesToFlush[$key]) === false) {
            $this->indexesToFlush[$key] = array($idx,$suffix);
        }
        
    }

    /**
     * Retrieve or insert a value in the index.
     *
     * @param string    $idx    name of the index
     * @param string    $suffix subpart identifier
     * @param string    $value  line to find in the index
     * @return int|bool          line number of the value in the index or false if writing the index failed
     * @author Tom N Harris <tnharris@whoopdedo.org>
     */
    protected function addIndexKey($idx, $suffix, $value) {
        $key = $idx.$suffix;
        if(isset($this->indexes[$key]) === false) {
            $this->indexes[$key] = parent::getIndex($idx, $suffix);
        }
        
        $id = array_search($value, $this->indexes[$key], true);
        
        if ($id === false) {
            $id = count($this->indexes[$key]);
            $this->indexes[$key][$id] = $value;
            if(isset($this->indexesToFlush[$key]) === false) {
                $this->indexesToFlush[$key] = array($idx,$suffix);
            }
        }
        
        return $id;
    }
    
    public function flushIndexes()
    {
        foreach($this->indexesToFlush as $indexPair) {
            parent::saveIndex($indexPair[0], $indexPair[1], $this->indexes[$indexPair[0].$indexPair[1]]);
        }
        $this->indexesToFlush = array();
    }
    
    
    /**
     * Insert or replace a tuple in a line.
     *
     * @author Tom N Harris <tnharris@whoopdedo.org>
     */
    protected function updateTuple($line, $id, $count) {
        if ($line !== '') {
            
            if(substr($line, 0, strlen($id)) == $id) { // add check here see if line start with id, makes our regex 10x faster
                $newLine = preg_replace('/^'.preg_quote($id,'/').'\*\d*/', '', $line);
            } else {
                $newLine = preg_replace('/:'.preg_quote($id,'/').'\*\d*/', '', $line);
            }
        }
        $newLine = trim($newLine, ':');
        if ($count) {
            if (strlen($newLine) > 0)
                return "$id*$count:".$newLine;
            else
                return "$id*$count".$newLine;
        }
        return $newLine;
    }
}



/**
 * Adds/updates the search index for the given page
 *
 * Locking is handled internally.
 *
 * @param string        $page   name of the page to index
 * @param boolean       $verbose    print status messages
 * @param boolean       $force  force reindexing even when the index is up to date
 * @return boolean              the function completed successfully
 * @author Tom N Harris <tnharris@whoopdedo.org>
 */
function enhanced_idx_addPage($page, $verbose=false, $force=false) {
    $idxtag = metaFN($page,'.indexed');
    // check if page was deleted but is still in the index
    if (!page_exists($page)) {
        if (!@file_exists($idxtag)) {
            if ($verbose) print("Indexer: $page does not exist, ignoring".DOKU_LF);
            return false;
        }
        $Indexer = enhanced_idx_get_indexer();
        $result = $Indexer->deletePage($page);
        if ($result === "locked") {
            if ($verbose) print("Indexer: locked".DOKU_LF);
            return false;
        }
        @unlink($idxtag);
        return $result;
    }

    // check if indexing needed
    if(!$force && @file_exists($idxtag)){
        if(trim(io_readFile($idxtag)) == idx_get_version()){
            $last = @filemtime($idxtag);
            if($last > @filemtime(wikiFN($page))){
                if ($verbose) print("Indexer: index for $page up to date".DOKU_LF);
                return false;
            }
        }
    }

    $indexenabled = p_get_metadata($page, 'internal index', METADATA_RENDER_UNLIMITED);
    if ($indexenabled === false) {
        $result = false;
        if (@file_exists($idxtag)) {
            $Indexer = enhanced_idx_get_indexer();
            $result = $Indexer->deletePage($page);
            if ($result === "locked") {
                if ($verbose) print("Indexer: locked".DOKU_LF);
                return false;
            }
            @unlink($idxtag);
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

    if ($result)
        io_saveFile(metaFN($page,'.indexed'), idx_get_version());
    if ($verbose) {
        print("Indexer: finished".DOKU_LF);
        return true;
    }
    return $result;
}



/**
 * Create an instance of the indexer.
 *
 * @return Doku_Indexer               a Doku_Indexer
 * @author Tom N Harris <tnharris@whoopdedo.org>
 */
function enhanced_idx_get_indexer() {
    static $Indexer;
    if (!isset($Indexer)) {
        $Indexer = new Doku_Indexer_Enhanced();
    }
    return $Indexer;
}