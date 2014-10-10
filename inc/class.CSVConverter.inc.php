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
 *     get_arrayFail(), get_arrayDone, get_numFields(),
 *     getCLIOutput()
 * 
 * @author Robert Szynal <RJSzynal@Gmail.com>
 */
class CSVConverter
{
    protected $fileLoc         = '';
    protected $testMode        = FALSE;
    protected $databaseConfig  = 'config/config.ebuyerTest.inc.php';
    protected $sourceCharSet   = 'UTF-8';
    protected $databaseCharSet = 'ISO-8859-1'; // the database charset is 'latin1' a.k.a ISO-8859-1, iso-ir-100, csISOLatin1, latin1, l1, IBM819, CP819
    protected $fieldTitles     = array();
    protected $numFields       = 0;
    protected $arrayIn         = array();
    protected $arrayOut        = array();
    protected $arrayErr        = array();
    protected $arrayFail       = array();
    protected $arrayRules      = array();
    protected $arrayExists     = array();
    protected $arrayDone       = array();
    protected $fieldTypes      = array('string', 'string', 'string', 'int', 'currency',
        'bool');
    protected $discontinued    = array('yes', 'Yes', 'YES', 'Y', 'y', 'Discontinued',
        'discontinued', 'DISCONTINUED', '1'); // Possible positive values for the discontinued column

    public function __construct($tempFileLoc, $isTest = FALSE)
    {
        $this->fileLoc  = $tempFileLoc;
        $this->testMode = $isTest;
        $this->arrayIn  = self::csvToArray($this->fileLoc);
        $this->arrayOut = self::rowLengthCheck($this->arrayIn);
        $this->arrayOut = self::recoverLongRows($this->arrayErr);
        $this->arrayOut = self::importRules($this->arrayOut);
        self::dbInsert($this->arrayOut);
    }

    /**
     * @desc takes a file location as input and outputs an array of the contents
     * @param string $fileIn - The location of the CSV
     * @throws Exception
     * @return array CSV data in array form
     */
    protected function csvToArray($fileIn = '')
    {
        if (!file_exists($fileIn) || !is_readable($fileIn)) {
            throw new Exception("ERROR: CSV file cannot be read or doesn't exist.".PHP_EOL);
        }

        $arrOut   = array();
        if (( $openFile = fopen($fileIn, 'r') ) !== FALSE) {// create a connection to the file
            while (( $row = fgetcsv($openFile) ) !== FALSE) {
                if (!$this->fieldTitles) {
                    $this->fieldTitles = $row; // record the field titles to use later
                    $this->numFields   = count($this->fieldTitles);
                } else {
                    $arrOut[] = $row;
                }
            }
            fclose($openFile); // close the connection to the file when we're done
        } else {
            throw new Exception("ERROR: Failed to open connection to CSV. Please try again.".PHP_EOL);
        }
        return $arrOut;
    }
// end of csvToArray

    /**
     * @desc Separate any rows which are not the correct length
     * @param array $arrIn - Array to process
     * @return array Array of successfully processed rows
     */
    protected function rowLengthCheck($arrIn = '')
    {
        $arrOut = array();
        foreach ($arrIn as $row) {
            if (count($row) !== $this->numFields) {
                $this->arrayErr[] = $row;
            } else {
                $arrOut[] = self::parseRow($row, $this->fieldTypes);
            }
        }
        return $arrOut;
    }
// end of rowLengthCheck

    /**
     * @desc Ensure each field of the array is the correct type
     * @param array $row - Array to parse
     * @param array $types - Array of field types
     * @return array Array of successfully parsed rows
     */
    private function parseRow($row, $types)
    {
        for ($i = 0; $i < count($types); $i++) {
            if ($types[$i] === 'string') {
                // Ensure encoding is correct for the database translating any characters to the destination character set
                $row[$i] = iconv($this->sourceCharSet,
                    $this->databaseCharSet."//TRANSLIT", (string) $row[$i]);
            } else if ($types[$i] === 'int') {
                $row[$i] = (int) $row[$i];
            } else if ($types[$i] === 'currency') {
                $row[$i] = (float) preg_replace("/([^0-9\\.])/i", "", $row[$i]); // Only accept numbers and a decimal place
            } else if ($types[$i] === 'bool') {
                $row[$i] = ( in_array($row[$i], $this->discontinued) ) ? TRUE : FALSE;
            }
        }
        return $row;
    }

    /**
     * @desc Try to recover bad rows which have too many fields
     * @param array $arrIn - Array of errored rows to process
     * @return array Array of correct length rows
     */
    protected function recoverLongRows($arrIn = '')
    {
        $arrayTemp = array();
        unset($this->arrayErr);

        foreach ($arrIn as $row) {
            if (count($row) > $this->numFields) {
                $qtyBadCommas = count($row) - $this->numFields;
                $arrayTemp[]  = SELF::quoteSplitField($row, $qtyBadCommas);
            } else {
                $this->arrayErr[] = $row;
            }
        }
        $arrOut = self::rowLengthCheck($arrayTemp); // Re-process the previously errored rows
        return array_merge($arrOut, $this->arrayOut);
    }

    /**
     * @desc Try to quote text blocks with commas in
     * @param array $row - Row to process
     * @param int $qtyBadCommas - number of extra commas in row
     * @return array fixed or untouched array returned
     */
    private function quoteSplitField($row, $qtyBadCommas)
    {
        $tempRow = $row;
        for ($i = 1; $i < count($tempRow); $i++) {
            /* Check if this field starts with a space, if so then it's likely it's part of a sentance and not a separator
             * Worst case senario it goes back into the error array and is reported at the end.
             */
            if (substr($tempRow[$i], 0, 1) === ' ') {
                if ($i === 1 || ( substr($tempRow[$i - 1], 0, 1) !== ' ' && $i !== 1 )) { // make sure the previous field wasn't also part of this text block
                    $tempRow[$i - 1] = '"'.$tempRow[$i - 1]; // Starting quote for text block
                }
                if (substr($tempRow[$i + 1], 0, 1) !== ' ') { // make sure the next field isn't also part of this text block
                    $tempRow[$i] = $tempRow[$i].'"'; // Ending quote for text block
                }
            }
        }
        $csvRow = implode(",", $tempRow); // Turn it back to a string so we can re-parse it
        if (substr_count($csvRow, ', ') === $qtyBadCommas) { // Have we caught all the bad commas?
            $arrOut = str_getcsv($csvRow); // Fixed row
        } else {
            $arrOut = $row; // Untouched row
        }
        return $arrOut;
    }

    /**
     * @desc Filter array through import rules, removing rows which don't pass
     * @param array $arrIn - Array to process
     * @return array Array of rows which pass the import rules
     */
    protected function importRules($arrIn = '')
    {
        $arrOut = array();
        foreach ($arrIn as $row) {
            //Any stock item which costs less that £5 AND has less than 10 stock will not be imported
            //Any stock items which cost over £1000 will not be imported
            if (($row[3] < 10 && $row[4] < 5.0) || $row[4] > 1000.0) {
                $this->arrayRules[] = $row;
            } else {
                $arrOut[] = $row;
            }
        }
        return $arrOut;
    }
// end of importRules

    /**
     * @desc Set database access configuration in preperation for connecting
     * @param string $databaseConfig - Location of database config file
     * @throws Exception
     * @return bool True on success
     */
    protected function setDatabaseConfig($databaseConfig)
    {
        if (file_exists($databaseConfig) && is_readable($databaseConfig)) {
            require( $databaseConfig );
        } else {
            throw new Exception('Database configuration file could not be found or read.'.PHP_EOL);
        }
        return TRUE;
    }
// end of setDatabaseConfig

    /**
     * @desc takes array as input and inserts each item into the database 
     * @param array $arrIn - Array of rows to insert
     * @return bool True on success
     */
    protected function dbInsert($arrIn)
    {
        self::setDatabaseConfig($this->databaseConfig);
        $dbEbuyer = new EbuyerDB('tblproductdata');
        $dbEbuyer->beginTransaction(); // Disable auto-commit to ensure script completes successfully before commiting to the DB

        foreach ($arrIn as $row) {
            $arrayReturned = $dbEbuyer->executeInsert($row);
            switch ($arrayReturned[0]) {
                case 'done':
                    $this->arrayDone[]   = $arrayReturned[1];
                    break;
                case 'fail':
                    $this->arrayFail[]   = $arrayReturned[1];
                    break;
                case 'exists':
                    $this->arrayExists[] = $arrayReturned[1];
                    break;
            }
        }
        if ($this->testMode) {
            $dbEbuyer->cancelTransaction();
        } else {
            $dbEbuyer->CommitTransaction();
        }
        return TRUE;
    }
// end of dbInsert

    /**
     * @desc create full CLI row for given item
     * @param array $row - Array of item information
     * @return string CLI row of item
     */
    private function printFullRowCLI($row)
    {
        return "|".$row[0]." |".str_pad($row[1], 13)."|".str_pad($row[2], 38)."|".str_pad($row[3],
                2)."|".str_pad($row[4], 7)."|".(($row[5]) ? "      " : "Active")."|".PHP_EOL;
    }
// end of printFullRowCLI

    /**
     * @desc returns CLI output showing the results of the class after process
     * @return string CLI output
     */
    public function getCLIOutput()
    {
        $stringOutput = '';

        if ($this->testMode) {
            $stringOutput .= str_pad("RUNNING IN TEST MODE", 79, "*",
                    STR_PAD_BOTH).PHP_EOL;
        }
        $stringOutput .= str_pad("Items Proccessed: ", 18).count($this->arrayIn).PHP_EOL;
        $stringOutput .= str_pad("Items Successful: ", 18).count($this->arrayDone).PHP_EOL;
        $stringOutput .= str_pad("Items Skipped: ", 18).count($this->arrayRules).PHP_EOL;
        $numErr = count($this->arrayErr) + count($this->arrayFail) + count($this->arrayExists);
        $stringOutput .= str_pad("Items Failed: ", 18).$numErr.PHP_EOL;

        if (count($this->arrayErr)) {
            $stringOutput .= str_pad("Skipped Items (Error)", 79, "-",
                    STR_PAD_BOTH).PHP_EOL;
            foreach ($this->arrayErr as $currVal) {
                $stringOutput .= "|".str_pad(implode(',', $currVal), 77)."|".PHP_EOL;
            }
        }

        if (count($this->arrayRules)) {
            $stringOutput .= str_pad("Skipped Items (Import Rules)", 79, "-",
                    STR_PAD_BOTH).PHP_EOL;
            foreach ($this->arrayRules as $currVal) {
                $stringOutput .= self::printFullRowCLI($currVal);
            }
        }

        if (count($this->arrayExists)) {
            $stringOutput .= str_pad("Skipped Items (Item already in DB)", 79,
                    "-", STR_PAD_BOTH).PHP_EOL;
            foreach ($this->arrayExists as $currVal) {
                $stringOutput .= self::printFullRowCLI($currVal);
            }
        }

        if (count($this->arrayFail)) {
            $stringOutput .= str_pad("Skipped Items (Item insertion failed)",
                    79, "-", STR_PAD_BOTH).PHP_EOL;
            foreach ($this->arrayFail as $currVal) {
                $stringOutput .= self::printFullRowCLI($currVal);
            }
        }

        if ($this->testMode) {
            if (count($this->arrayDone)) {
                $stringOutput .= str_pad("Successful Rows", 79, "-",
                        STR_PAD_BOTH).PHP_EOL;
                foreach ($this->arrayDone as $currVal) {
                    $stringOutput .= self::printFullRowCLI($currVal);
                }
            }
        }
        return $stringOutput;
    }
// end of getCLIOutput
// ***********************Public Get Functions*********************** 

    /**
     * @desc Outputs arrayIn variable
     * @return var Returns variable or NULL
     */
    public function get_arrayIn()
    {
        return (isset($this->arrayIn)) ? $this->arrayIn : NULL;
    }
//end of get_arrayIn

    /**
     * @desc Outputs arrayErr variable
     * @return var Returns variable or NULL
     */
    public function get_arrayErr()
    {
        return (isset($this->arrayErr)) ? $this->arrayErr : NULL;
    }
//end of get_arrayErr

    /**
     * @desc Outputs arrayOut variable
     * @return var Returns variable or NULL
     */
    public function get_arrayOut()
    {
        return (isset($this->arrayOut)) ? $this->arrayOut : NULL;
    }
//end of get_arrayOut

    /**
     * @desc Outputs arrayRules variable
     * @return var Returns variable or NULL
     */
    public function get_arrayRules()
    {
        return (isset($this->arrayRules)) ? $this->arrayRules : NULL;
    }
//end of get_arrayRules

    /**
     * @desc Outputs arrayExists variable
     * @return var Returns variable or NULL
     */
    public function get_arrayExists()
    {
        return (isset($this->arrayExists)) ? $this->arrayExists : NULL;
    }
//end of get_arrayExists

    /**
     * @desc Outputs arrayFail variable
     * @return var Returns variable or NULL
     */
    public function get_arrayFail()
    {
        return (isset($this->arrayFail)) ? $this->arrayFail : NULL;
    }
//end of get_arrayFail

    /**
     * @desc Outputs arrayFail variable
     * @return var Returns variable or NULL
     */
    public function get_arrayDone()
    {
        return (isset($this->arrayDone)) ? $this->arrayDone : NULL;
    }
//end of get_arrayFail

    /**
     * @desc Outputs numFields variable
     * @return var Returns variable
     */
    public function get_numFields()
    {
        return $this->numFields;
    }
//end of get_numFields
}
