<?php
// Read the options from the command line input
$options = getopt("f:t");
// Ensure we're in utf-8 for reading in the csv
setlocale(LC_ALL, "en_GB.UTF-8");

// This will automatically load classes when they're needed as long as they follow the correct naming convention
spl_autoload_register(function ($class) {
    require_once 'inc/class.'.$class.'.inc.php';
});

if (!isset($options["f"])) {
    exit("ERROR: No input file, set with -f (e.g. -f //file/location/here.csv)");
}

// Create a new instance of our class to begin proccessing
try {
    $itemList = new CSVConverter($options["f"], isset($options["t"]));
    echo $itemList->getCLIOutput();
} catch (Exception $e) {
    echo $e->getMessage();
}
