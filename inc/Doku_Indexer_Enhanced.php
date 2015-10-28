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
        return true;
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
     * @param string     $line
     * @param int|string $id
     * @param int        $count
     * @return mixed|string
     */
    protected function updateTuple($line, $id, $count) {
        if ($line !== '') {

            if(substr($line, 0, strlen($id)) == $id) { // add check here see if line start with id, makes our regex 10x faster
                $line = preg_replace('/^'.preg_quote($id,'/').'\*\d*/', '', $line);
            } else {
                $line = preg_replace('/:'.preg_quote($id,'/').'\*\d*/', '', $line);
            }
        }
        $line = trim($line, ':');
        if ($count) {
            if (strlen($line) > 0)
                return "$id*$count:".$line;
            else
                return "$id*$count".$line;
        }
        return $line;
    }
}


/**
 * Create an instance of the indexer.
 *
 * @return Doku_Indexer_Enhanced
 * @author Tom N Harris <tnharris@whoopdedo.org>
 */
function enhanced_idx_get_indexer() {
    static $Indexer;
    if (!isset($Indexer)) {
        $Indexer = new Doku_Indexer_Enhanced();
    }
    return $Indexer;
}
