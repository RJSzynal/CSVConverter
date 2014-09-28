<?php

/**
 * CSV conversion class
 *
 * This class takes the file location of a csv
 * then proccesses it for bad rows, runs it by
 * the company import rules and finally inserts
 * the remaining rows into the database
 *
 * public functions - get_arrayIn(), get_arrayOut(),
 *     get_arrayErr(), get_arrayRules(), get_arrayExists(),
 *     get_arrayFail(), get_numFields()
 * 
 * @author Robert Szynal <RJSzynal@Gmail.com>
 */
class CSV {

    protected $fileLoc = '';
    protected $fieldTitles = array();
    protected $numFields = 0;
    protected $mainArray = array();
    protected $fieldTypes = array('string', 'string', 'string', 'int', 'currency', 'bool');
    protected $discontinued = array('yes', 'Yes', 'YES', 'Y', 'y', 'Discontinued', 'discontinued', 'DISCONTINUED', '1'); // Possible positive values for the discontinued column

    public function __construct($tempFileLoc = '') {
        $this->fileLoc = $tempFileLoc;
        $this->mainArray['in'] = self::csvToArray($this->fileLoc);
        $this->mainArray['out'] = self::processArray($this->mainArray['in']);
        $this->mainArray['out'] = self::recoverLongRows($this->mainArray['err']);
        $this->mainArray['out'] = self::importRules($this->mainArray['out']);
        self::dbInsert($this->mainArray['out']);
    }

    /**
     * @desc takes a file location as input and outputs an array of the contents
     * @param string $fileIn - The location of the CSV
     * @return array CSV data in array form
     */
    protected function csvToArray($fileIn = '') {
        if ( !file_exists($fileIn) || !is_readable($fileIn) ) {
            exit("ERROR: CSV file cannot be read or doesn't exist");
        }

        $arrayOut = array();
        if ( ( $openFile = fopen($fileIn, 'r') ) !== FALSE ) {// create a connection to the file
            while ( ( $row = fgetcsv($openFile) ) !== FALSE ) {
                if ( !$this->fieldTitles ) {
                    $this->fieldTitles = $row; // record the field titles to use later
                    $this->numFields = count($this->fieldTitles);
                } else {
                    $arrayOut[] = $row;
                }
            }
            fclose($openFile); // close the connection to the file when we're done
        } else {
            exit("ERROR: Failed to open connection to CSV");
        }
        return $arrayOut;
    }
// end of csvToArray

    /**
     * @desc Ensure each field of the array is correctly formatted
     * @param array $arrIn - Array to process
     * @return array Array of successfully processed rows
     */
    protected function processArray($arrIn = '') {
        $arrayOut = array();
        foreach ( $arrIn as $row ) {
            if ( count($row) !== $this->numFields ) {
                $this->mainArray['err'][] = $row;
            } else {
                $arrayOut[] = self::parseRow($row, $this->fieldTypes);
            }
        }
        return $arrayOut;
    }
// end of processArray

    /**
     * @desc Ensure each field of the array is the correct type
     * @param array $row - Array to parse
     * @param array $types - Array of field types
     * @return array Array of successfully parsed rows
     */
    private function parseRow($row, $types) {
        for ( $i = 0; $i < count($types); $i++ ) {
            if ( $types[$i] === 'string' ) {
                $row[$i] = iconv("UTF-8", "ISO-8859-1//TRANSLIT", (string) $row[$i]);
            } else if ( $types[$i] === 'int' ) {
                $row[$i] = (int) $row[$i];
            } else if ( $types[$i] === 'currency' ) {
                $row[$i] = (float) preg_replace("/([^0-9\\.])/i", "", $row[$i]); // Only accept numbers and a decimal place
            } else if ( $types[$i] === 'bool' ) {
                $row[$i] = ( in_array($row[$i], $this->discontinued) ) ? TRUE : FALSE;
            }
        }
        return $row;
    }

    /**
     * @desc Try to recover bad rows which have too many fields
     * @param array $arrIn - Array to process
     * @return array CSV data in array form
     */
    protected function recoverLongRows($arrIn = '') {
        $arrayTemp = array();
        unset($this->mainArray['err']);

        foreach ( $arrIn as $row ) {
            if ( count($row) > $this->numFields ) {
                $qtyBadCommas = count($row) - $this->numFields;
                $arrayTemp[] = SELF::quoteSplitField($row, $qtyBadCommas);
            } else {
                $this->mainArray['err'][] = $row;
            }
        }
        $arrayOut = self::processArray($arrayTemp);
        return array_merge($arrayOut, $this->mainArray['out']);
    }

    /**
     * @desc Try to recover bad rows which have too many fields
     * @param array $row - Array to process
     * @param int $qtyBadCommas - number of extra commas in array
     * @return array fixed or failed array returned
     */
    private function quoteSplitField($row, $qtyBadCommas) {
        $tempRow = $row;
        for ( $i = 1; $i < count($tempRow); $i++ ) {
            /* Check if this field starts with a space, if so then it's likely it's part of a sentance and not a separator
             * Worst case senario it goes back into the error array and is reported at the end.
             */
            if ( substr($tempRow[$i], 0, 1) === ' ' ) {
                if ( $i === 1 || ( substr($tempRow[$i - 1], 0, 1) !== ' ' && $i !== 1 ) ) { // make sure the previous field wasn't also part of this text block
                    $tempRow[$i - 1] = '"' . $tempRow[$i - 1]; // Starting quote for text block
                }
                if ( substr($tempRow[$i + 1], 0, 1) !== ' ' ) { // make sure the next field isn't also part of this text block
                    $tempRow[$i] = $tempRow[$i] . '"'; // Ending quote for text block
                }
            }
        }
        $csvRow = implode(",", $tempRow); // Turn it back to a string so we can re-parse it
        if ( substr_count($csvRow, ', ') === $qtyBadCommas ) { // Have we caught all the bad commas?
            $arrayOut = str_getcsv($csvRow); // Fixed row
        } else {
            $arrayOut = $row; // Untouched row
        }
        return $arrayOut;
    }

    /**
     * @desc Filter array through import rules, removing rows which don't pass
     * @param array $arrIn - Array to process
     * @return array Array of successfully processed rows
     */
    protected function importRules($arrIn = '') {
        $arrayOut = array();
        foreach ( $arrIn as $row ) {
            //Any stock item which costs less that �5 AND has less than 10 stock will not be imported
            //Any stock items which cost over �1000 will not be imported
            if ( ($row[3] < 10 && $row[4] < 5.0) || $row[4] > 1000.0 ) {
                $this->mainArray['rules'][] = $row;
            } else {
                $arrayOut[] = $row;
            }
        }
        return $arrayOut;
    }
// end of importRules

    /**
     * @desc takes array as input and inserts each item into the database 
     * @param array $arrIn - Array to process
     * @return bool True on success
     */
    protected function dbInsert($arrIn = '') {
        unset($this->mainArray['out']);
        require( "inc/db.ebuyerTest.inc.php" );
        $database = new Database('tblproductdata');

        $database->beginTransaction(); // Disable auto-commit to ensure script completes successfully before commiting to the DB
        // Prep the queries we'll be using before the loop to improve efficiency
        $insertQry = 'INSERT INTO tblproductdata (strProductCode, strProductName, strProductDesc, intStock, numCost, dtmAdded, dtmDiscontinued) '
                . 'VALUES (:code, :name, :description, :stock, :cost, CURRENT_TIMESTAMP, :discontinued)';
        $database->prepareInsert($insertQry);

        foreach ( $arrIn as $row ) {
            $arrayInsert = array(
                ':code' => $row[0],
                ':name' => $row[1],
                ':description' => $row[2],
                ':stock' => $row[3],
                ':cost' => $row[4],
                ':discontinued' => ($row[5]) ? date('Y-m-d H:i:s') : NULL);
            $arrayReturned = $database->executeInsert($arrayInsert);
            switch ($arrayReturned[0]) {
                case 'out':
                    $this->mainArray['out'][] = $arrayReturned[1];
                    break;
                case 'fail':
                    $this->mainArray['fail'][] = $arrayReturned[1];
                    break;
                case 'exists':
                    $this->mainArray['exists'][] = $arrayReturned[1];
                    break;
            }
        }
        if ( $GLOBALS['test'] ) {
            $database->cancelTransaction();
        } else {
            $database->endTransaction();
        }
        return TRUE;
    }
// end of importRules
// ***********************Public Get Functions*********************** 

    /**
     * @desc Outputs mainArray['in'] variable
     * @return var Returns variable or NULL
     */
    public function get_arrayIn() {
        return (isset($this->mainArray['in'])) ? $this->mainArray['in'] : NULL;
    }
//end of get_arrayIn

    /**
     * @desc Outputs mainArray['err'] variable
     * @return var Returns variable or NULL
     */
    public function get_arrayErr() {
        return (isset($this->mainArray['err'])) ? $this->mainArray['err'] : NULL;
    }
//end of get_arrayErr

    /**
     * @desc Outputs mainArray['out'] variable
     * @return var Returns variable or NULL
     */
    public function get_arrayOut() {
        return (isset($this->mainArray['out'])) ? $this->mainArray['out'] : NULL;
    }
//end of get_arrayOut

    /**
     * @desc Outputs mainArray['rules'] variable
     * @return var Returns variable or NULL
     */
    public function get_arrayRules() {
        return (isset($this->mainArray['rules'])) ? $this->mainArray['rules'] : NULL;
    }
//end of get_arrayRules

    /**
     * @desc Outputs mainArray['exists'] variable
     * @return var Returns variable or NULL
     */
    public function get_arrayExists() {
        return (isset($this->mainArray['exists'])) ? $this->mainArray['exists'] : NULL;
    }
//end of get_arrayExists

    /**
     * @desc Outputs mainArray['fail'] variable
     * @return var Returns variable or NULL
     */
    public function get_arrayFail() {
        return (isset($this->mainArray['fail'])) ? $this->mainArray['fail'] : NULL;
    }
//end of get_arrayFail

    /**
     * @desc Outputs numFields variable
     * @return var Returns variable
     */
    public function get_numFields() {
        return $this->numFields;
    }
//end of get_numFields
}

?>