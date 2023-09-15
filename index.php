<?php
header('Content-Type: application/json; charset=utf-8');

include('SpaClient.php');
$spaClient = new SpaClient('192.168.178.xxx');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if(isset($_GET['setTemp'])) {
        $action = (float) $_GET['setTemp'];
        if (($action >= 10 && $action <= 37) && ($action * 10 % 5 == 0)) {
            $spaClient->setTemperature($action);
        }
    }
    if(isset($_GET['setPump1'])) {
        $action = $_GET['setPump1'];
        switch ($action) {
            case 'High':
            case 'Low':
                $spaClient->setPump1($action);
                break;
            default:
                $spaClient->setPump1('Off');
                break;
        }
    }
    if(isset($_GET['setPump2'])) {
        $action = $_GET['setPump2'];
        switch ($action) {
            case 'High':
            case 'Low':
                $spaClient->setPump2($action);
                break;
            default:
                $spaClient->setPump2('Off');
                break;
        }
    }
    if(isset($_GET['setLight'])) {
        $action = $_GET['setLight'];
        switch ($action) {
            case 'On':
                $spaClient->setLight(true);
                break;
            default:
                $spaClient->setLight(false);
                break;
        }
    }
}

$spaClient->readAllMsg();
echo json_encode($spaClient->getStatusForFhem());