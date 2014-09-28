<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

class Database {

    private $host = DB_HOST;
    private $user = DB_USER;
    private $pass = DB_PASS;
    private $dbname = DB_NAME;
    private $productDB;
    private $error;
    private $stmtInsert;
    private $stmtExists;

    public function __construct($table) {

        $dsn = 'mysql:host=' . $this->host . ';dbname=' . $this->dbname;
        // Set options for best efficiency
        $options = array(
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        );

        try {
            $this->productDB = new PDO($dsn, $this->user, $this->pass, $options);
        } catch (PDOException $e) {
            $this->error = 'Connection failed: ' . $e->getMessage();
        }
        $this->stmtExists = $this->productDB->prepare('SELECT COUNT(*) from ' . $table . ' WHERE strProductCode = ?');
    }

    public function prepareInsert($query) {
        $this->stmtInsert = $this->productDB->prepare($query);
    }

    public function executeInsert($row) {
        $arrayOut = array();
        // If it's already in the database then we don't want to add it again
        if ( !self::executeExists($row[':code']) ) {
            try {
                $this->stmtInsert->execute($row);
                $arrayOut = array('out', array_values($row));
            } catch (PDOException $e) {
                echo 'Insertion of ' . $row[0] . ' failed: ' . $e->getMessage() . PHP_EOL;
                $arrayOut = array('fail', array_values($row));
            }
        } else {
            $arrayOut = array('exists', array_values($row));
        }
        return $arrayOut;
    }

    private function executeExists($code) {
        $this->stmtExists->execute(array($code));
        return $this->stmtExists->fetchColumn();
    }

    public function beginTransaction() {
        return $this->productDB->beginTransaction();
    }

    public function endTransaction() {
        return $this->productDB->commit();
    }

    public function cancelTransaction() {
        return $this->productDB->rollBack();
    }
}
