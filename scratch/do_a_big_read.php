<?php
$db = new SQLite3('test.db');
$results = $db->query('select * from lotsofvalues a join lotsofvalues b');

$count = 0;
while ($row = $results->fetchArray()) {
    $count++;
}

echo "There were $count rows.\n";
