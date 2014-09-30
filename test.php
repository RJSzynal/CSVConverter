<?php

// This will automatically load classes when they're needed as long as they follow the correct naming convention
spl_autoload_register(function ($class) {
    require_once 'inc/class.' . $class . '.inc.php';
});

// Read the options from the command line input
$options = getopt("f:t");
if ( !isset($options["f"]) ) {
    exit("ERROR: No input file, set with -f (e.g. -f//file/location/here.csv)");
}
$GLOBALS['test'] = isset($options["t"]);
if ( $GLOBALS['test'] ) {
    echo str_pad("RUNNING IN TEST MODE", 79, "*", STR_PAD_BOTH) . PHP_EOL;
}

// Create a new instance of our class to begin proccessing
$itemList = new CSVConverter($options["f"]);

/* **************************************************************
 * Everything below is just for displaying to the command line UI
 **************************************************************** */
$numIn = count($itemList->get_arrayIn());
echo "Items Proccessed: $numIn" . PHP_EOL;

$numSuccess = count($itemList->get_arrayDone());
echo "Items Successful: $numSuccess" . PHP_EOL;

$numErr = count($itemList->get_arrayErr()) + count($itemList->get_arrayFail()) + count($itemList->get_arrayExists()) + count($itemList->get_arrayRules());
echo "Items Skipped:    $numErr" . PHP_EOL;

function printFull($row) {
    echo "|" . $row[0] . " |" . str_pad($row[1], 13) . "|" . str_pad($row[2], 38) . "|" . str_pad($row[3], 2) . "|" . str_pad($row[4], 7) . "|" . (($row[5]) ? "      " : "Active") . "|" . PHP_EOL;
}
$arrayErr = $itemList->get_arrayErr();
if ( count($arrayErr) ) {
    echo str_pad("Skipped Items (Error)", 79, "-", STR_PAD_BOTH) . PHP_EOL;
    foreach ( $arrayErr as $currVal ) {
        echo "|" . $currVal[0] . " |" . str_pad($currVal[1], 13) . "|" . str_pad($currVal[2], 38) . "|" . PHP_EOL;
    }
}

$arrayRules = $itemList->get_arrayRules();
if ( count($arrayRules) ) {
    echo str_pad("Skipped Items (Import Rules)", 79, "-", STR_PAD_BOTH) . PHP_EOL;
    foreach ( $arrayRules as $currVal ) {
        printFull($currVal);
    }
}

$arrayExists = $itemList->get_arrayExists();
if ( count($arrayExists) ) {
    echo str_pad("Skipped Items (Item already in DB)", 79, "-", STR_PAD_BOTH) . PHP_EOL;
    foreach ( $arrayExists as $currVal ) {
        printFull($currVal);
    }
}

$arrayFail = $itemList->get_arrayFail();
if ( count($arrayFail) ) {
    echo str_pad("Skipped Items (Item insertion failed)", 79, "-", STR_PAD_BOTH) . PHP_EOL;
    foreach ( $arrayFail as $currVal ) {
        printFull($currVal);
    }
}

if ( $GLOBALS['test'] ) {
    $arraySuccess = $itemList->get_arrayOut();
    echo str_pad("Successful Rows", 79, "-", STR_PAD_BOTH) . PHP_EOL;
    foreach ( $arraySuccess as $currVal ) {
        printFull($currVal);
    }
}
?>