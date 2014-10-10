<?php

/**
 * Ebuyer database class
 *
 * This class prepares the connection to the database
 * and performs existance checks on each item before
 * inserting new items into the database
 *
 * public functions - prepareInsert(), executeInsert(),
 *     prepareExists(), executeExists(), beginTransaction(),
 *     CommitTransaction(), cancelTransaction()
 * 
 * @author Robert Szynal <RJSzynal@Gmail.com>
 */
class EbuyerDB
{
    private $host   = DB_HOST;
    private $user   = DB_USER;
    private $pass   = DB_PASS;
    private $dbname = DB_NAME;
    private $productDB;
    private $error;
    private $stmtInsert;
    private $stmtExists;

    public function __construct($tblSelected)
    {

        $dsn     = 'mysql:host='.$this->host.';dbname='.$this->dbname;
        // Set options for best efficiency
        $options = array(
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        );

        try {
            $this->productDB = new PDO($dsn, $this->user, $this->pass, $options);
        } catch (PDOException $e) {
            $this->error = 'Connection failed: '.$e->getMessage();
        }
        // Prep the queries we'll be using before the loop to improve efficiency
        self::prepareInsert($tblSelected);
        self::prepareExists($tblSelected);
    }

    /**
     * @desc Prepares the insert query
     * @param string $table - The table to connect to in the database
     * @return bool True on success
     */
    public function prepareInsert($table)
    {
        $qryInsert        = 'INSERT INTO '.$table.' (strProductCode, strProductName, strProductDesc, intStock, numCost, dtmAdded, dtmDiscontinued) '
            .'VALUES (:code, :name, :description, :stock, :cost, CURRENT_TIMESTAMP, :discontinued)';
        $this->stmtInsert = $this->productDB->prepare($qryInsert);
        return TRUE;
    }

    /**
     * @desc Executes the insert query
     * @param array $row - The row to insert
     * @return array An array of done/fail/exists string and the row array
     */
    public function executeInsert($row)
    {
        $arrOut      = array();
        $arrayInsert = array(
            ':code' => $row[0],
            ':name' => $row[1],
            ':description' => $row[2],
            ':stock' => $row[3],
            ':cost' => $row[4],
            ':discontinued' => ($row[5]) ? date('Y-m-d H:i:s') : NULL);
        // If it's already in the database then we don't want to add it again
        if (!self::executeExists($arrayInsert[':code'])) {
            try {
                $this->stmtInsert->execute($arrayInsert);
                $arrOut = array('done', array_values($arrayInsert));
            } catch (PDOException $e) {
                echo 'Insertion of '.$arrayInsert[0].' failed: '.$e->getMessage().PHP_EOL;
                $arrOut = array('fail', array_values($arrayInsert));
            }
        } else {
            $arrOut = array('exists', array_values($arrayInsert));
        }
        return $arrOut;
    }

    /**
     * @desc Prepares the exists query to check for the existance of an item
     * @param string $table - The table to connect to in the database
     * @return bool True on success
     */
    public function prepareExists($table)
    {
        $qryExists        = 'SELECT COUNT(*) from '.$table.' WHERE strProductCode = ?';
        $this->stmtExists = $this->productDB->prepare($qryExists);
        return True;
    }

    /**
     * @desc Executes the exists query to check if the item is already in the DB
     * @param array $code - The product code of the item to insert
     * @return int Number of rows found
     */
    private function executeExists($code)
    {
        $this->stmtExists->execute(array($code));
        return $this->stmtExists->fetchColumn();
    }

    /**
     * @desc Begins a transaction by disabling auto-commit
     * @return bool True on success
     */
    public function beginTransaction()
    {
        return $this->productDB->beginTransaction();
    }

    /**
     * @desc Commits the transaction
     * @return bool True on success
     */
    public function CommitTransaction()
    {
        return $this->productDB->commit();
    }

    /**
     * @desc Rolls back the transaction
     * @return bool True on success
     */
    public function cancelTransaction()
    {
        return $this->productDB->rollBack();
    }
}
