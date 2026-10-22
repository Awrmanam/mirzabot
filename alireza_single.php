<?php
require_once 'config.php';
require_once 'x-ui_single.php';
require_once 'request.php';
ini_set('error_log', 'error_log');

function alirezaApiPostWithReauth(array $panel, $url, $payload, array $headers = [])
{
    $sendRequest = function () use ($url, $payload, $headers) {
        $req = new CurlRequest($url);
        if (!empty($headers)) {
            $req->setHeaders($headers);
        }
        $req->setCookie('cookie.txt');
        return $req->post($payload);
    };

    login($panel['code_panel']);
    $response = $sendRequest();
    $status = (int) ($response['status'] ?? 0);

    if (in_array($status, [301, 302, 303, 307, 308, 401, 403], true)) {
        if (is_file('cookie.txt')) {
            @unlink('cookie.txt');
        }
        login($panel['code_panel'], false);
        $response = $sendRequest();
    }

    if (is_file('cookie.txt')) {
        @unlink('cookie.txt');
    }

    return $response;
}

function get_clinetsalireza($username,$namepanel){
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel,"select");
    login($marzban_list_get['code_panel']);
    $curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => rtrim((string) $marzban_list_get['url_panel'], '/') . '/xui/API/inbounds',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_SSL_VERIFYHOST =>  false,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_TIMEOUT_MS => 4000,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'GET',
  CURLOPT_HTTPHEADER => array(
    'Accept: application/json'
  ),
  CURLOPT_COOKIEFILE => 'cookie.txt',
));
$output = [];
$response = json_decode(curl_exec($curl),true)['obj'];
if(!isset($response))return;
foreach ($response as $client){
    $clientdata= json_decode($client['settings'],true)['clients'];
    foreach($clientdata as $clinets){
    if($clinets['email'] == $username){
        $output[] = $clinets;
        break;
    }
    }
    $clientStats= $client['clientStats'];
    foreach($clientStats as $clinetsup){
    if($clinetsup['email'] == $username){
        $output[] = $clinetsup;
        break;
    }
    }
    
}
curl_close($curl);
unlink('cookie.txt');
return $output;
}
function addClientalireza_singel($namepanel, $usernameac, $Expire,$Total, $Uuid,$Flow,$subid,$inboundid){
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel,"select");
    $config = array(
        "id" => intval($inboundid),
        'settings' => json_encode(array(
            'clients' => array(
                array(
                "id" => $Uuid,
                "flow" => $Flow,
                "email" => $usernameac,
                "totalGB" => $Total,
                "expiryTime" => $Expire,
                "enable" => true,
                "tgId" => "",
                "subId" => $subid,
                "reset" => 0
            )),
             'decryption' => 'none',
            'fallbacks' => array(),
        ))
        );

    $configpanel = json_encode($config,true);
    $url = rtrim((string) $marzban_list_get['url_panel'], '/') . '/xui/API/inbounds/addClient';
    $headers = array(
            'Accept: application/json',
            'Content-Type: application/json',
    );
    return alirezaApiPostWithReauth($marzban_list_get, $url, $configpanel, $headers);
}
function updateClientalireza($namepanel, $username,array $config){
    $UsernameData = get_clinetsalireza($username,$namepanel)[0];
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel,"select");
    $configpanel = json_encode($config,true);
    $url = rtrim((string) $marzban_list_get['url_panel'], '/') . '/xui/API/inbounds/updateClient/' . $UsernameData['id'];
    $headers = array(
            'Accept: application/json',
            'Content-Type: application/json',
    );
    return alirezaApiPostWithReauth($marzban_list_get, $url, $configpanel, $headers);
}
function ResetUserDataUsagealirezasin($usernamepanel, $namepanel){
    $data_user = get_clinetsalireza($usernamepanel,$namepanel)[0];
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel,"select");
    $url = rtrim((string) $marzban_list_get['url_panel'], '/') . "/xui/API/inbounds/{$marzban_list_get['inboundid']}/resetClientTraffic/" . $data_user['email'];
    $headers = array(
            'Accept: application/json',
            'Content-Type: application/json',
    );
    return alirezaApiPostWithReauth($marzban_list_get, $url, [], $headers);
}
function removeClientalireza_single($location,$username){
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $location,"select");
    $data_user = get_clinetsalireza($username,$location)[0];
    $url = rtrim((string) $marzban_list_get['url_panel'], '/') . "/xui/API/inbounds/{$marzban_list_get['inboundid']}/delClient/" . $data_user['id'];
    $headers = array(
            'Accept: application/json',
            'Content-Type: application/json',
    );
    return alirezaApiPostWithReauth($marzban_list_get, $url, [], $headers);
    
}
function get_onlineclialireza($name_panel,$username){
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $name_panel,"select");
    login($marzban_list_get['code_panel']);
    $curl = curl_init();
curl_setopt_array($curl, array(
  CURLOPT_URL => rtrim((string) $marzban_list_get['url_panel'], '/') . '/xui/API/inbounds/onlines',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_SSL_VERIFYHOST =>  false,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'POST',
  CURLOPT_HTTPHEADER => array(
    'Accept: application/json'
  ),
  CURLOPT_COOKIEFILE => 'cookie.txt',
));
$response = json_decode(curl_exec($curl),true)['obj'];
if($response == null)return "offline";
if(in_array($username,$response))return "online";
return "offline";
curl_close($curl);
unlink('cookie.txt');

}