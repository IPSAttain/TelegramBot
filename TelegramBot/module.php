<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/WebHookModule.php';
include_once __DIR__ . '/../libs/vendor/autoload.php';

class TelegramBot extends WebHookModule
{
    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID, 'telegram/' . $InstanceID);
    }

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('BotApiKey', '');
        $this->RegisterPropertyString('BotUsername', '');

        $this->RegisterPropertyString('AllowList', '[]');
        $this->RegisterPropertyString('ActionList', '[]');

        $this->RegisterAttributeString("Buffer", "");
        $this->RegisterVariableString("HTMLTable", "Telegram Events","~HTMLBox",10);
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        if ($this->ReadPropertyString('BotApiKey')) {
            $cc_id = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}')[0];
            if (IPS_GetInstance($cc_id)['InstanceStatus'] == IS_ACTIVE) {
                $webhook_url = CC_GetConnectURL($cc_id) . '/hook/telegram/' . $this->InstanceID;
                try {
                    $telegram = new Longman\TelegramBot\Telegram($this->ReadPropertyString('BotApiKey'), $this->ReadPropertyString('BotUsername'));

                    $result = $telegram->setWebhook($webhook_url);
                    if (!$result->isOk()) {
                        $this->SetStatus(203);
                        echo $this->Translate('Setting webhook failed!');
                    } else {
                        $this->SetStatus(IS_ACTIVE);
                    }
                } catch (Longman\TelegramBot\Exception\TelegramException $e) {
                    $this->SetStatus(202);
                    echo $e->getMessage();
                }
            } else {
                $this->SetStatus(201);
            }
        } else {
            $this->SetStatus(IS_INACTIVE);
        }
    }

    public function SendMessage(string $Text)
    {
        // Send message to everyone
        $recipients = json_decode($this->ReadPropertyString('AllowList'), true);
        foreach ($recipients as $recipient) {
            $this->SendMessageEx($Text, strval($recipient['UserID']));
        }
    }

    public function SendMessageEx(string $Text, string $NameOrChatID)
    {
        try {
            $telegram = new Longman\TelegramBot\Telegram($this->ReadPropertyString('BotApiKey'), $this->ReadPropertyString('BotUsername'));

            // Try to find the name and map to ChatID
            $NameOrChatID = $this->NameToUserID($NameOrChatID);

            // Check formatting options of the message
            if ($Text != strip_tags($Text)) {
                $parse_mode = 'HTML';
            } else {
                $parse_mode = '';
            }

            // Send message
            $result = Longman\TelegramBot\Request::sendMessage([
                'chat_id' => $NameOrChatID,
                'text'    => $Text,
                'parse_mode' => $parse_mode,
            ]);

            if (!$result->isOk()) {
                echo $this->Translate('Sending message failed!');
            }
        } catch (Longman\TelegramBot\Exception\TelegramException $e) {
            echo $e->getMessage();
        }
    }

    public function SendImage(int $MediaID)
    {
        // Send message to everyone
        $recipients = json_decode($this->ReadPropertyString('AllowList'), true);
        foreach ($recipients as $recipient) {
            $this->SendImageEx($MediaID, strval($recipient['UserID']));
        }
    }

    public function SendImageEx(int $MediaID, string $NameOrChatID)
    {
        try {
            $telegram = new Longman\TelegramBot\Telegram($this->ReadPropertyString('BotApiKey'), $this->ReadPropertyString('BotUsername'));

            // Try to find the name and map to ChatID
            $NameOrChatID = $this->NameToUserID($NameOrChatID);

            // Prepare stream (we don't want to do any file I/O)
            $stream = \GuzzleHttp\Psr7\Utils::streamFor(
                base64_decode(IPS_GetMediaContent($MediaID)),
                ['metadata' => ['uri' => basename(IPS_GetMedia($MediaID)['MediaFile'])]]
            );

            // Send message
            $result = Longman\TelegramBot\Request::sendPhoto([
                'chat_id' => $NameOrChatID,
                'caption' => IPS_GetName($MediaID),
                'photo'   => $stream,
            ]);

            if (!$result->isOk()) {
                echo $this->Translate('Sending message failed!');
            }
        } catch (Longman\TelegramBot\Exception\TelegramException $e) {
            echo $e->getMessage();
        }
    }

    /**
     * This function will be called by the hook control. Visibility should be protected!
     */
    protected function ProcessHookData()
    {
        $data = file_get_contents('php://input');
        $this->SendDebug('Event', $data, 0);

        //Parse Event data from JSON
        $data = json_decode($data, true);

        //Check if user has permission to start this action
        $recipients = json_decode($this->ReadPropertyString('AllowList'), true);
        $found = false;
        foreach ($recipients as $recipient) {
            if ($recipient['UserID'] == $data['message']['from']['id']) {
                $found = true;
                break;
            }
        }

        // Store all Input into an Variable
        $this->SetValueHTML($data['message']['from']['id'],$data['message']['text'],$data['message']['from']['first_name'],$data['message']['from']['last_name'], $data['message']['from']['username']);
        
        //Notify user that he is not allowed to do that
        if (!$found) {
            $this->SendDebug('SECURITY', sprintf('Access denied to %s %s (%d)', $data['message']['from']['first_name'], $data['message']['from']['last_name'], $data['message']['from']['id']), 0);
            if ($data['message']['from']['language_code'] == 'de') {
                $this->SendMessageEx($this->Translate('Access denied!'), strval($data['message']['from']['id']));
            }
            else {
                $this->SendMessageEx('Access denied!', strval($data['message']['from']['id']));
            }
            return;
        }

        //Check if we know the action and can execute it
        $actions = json_decode($this->ReadPropertyString('ActionList'), true);
        $found = false;
        $msgarray = preg_split('/ /', $data['message']['text'], 4);
        foreach ($actions as $action) {
            if ($action['Command'] == strtolower($msgarray[0])) {
                $actionPayload = json_decode($action['Action'], true);

                //Send debug that we will execute
                $this->SendDebug('EXECUTING', sprintf('Action %s is executing by %s %s (%d)', $data['message']['text'], $data['message']['from']['first_name'], $data['message']['from']['last_name'], $data['message']['from']['id']), 0);
                IPS_RunAction($actionPayload['actionID'], array_merge(['TARGET' => $actionPayload['targetID'], 'INSTANCE' => $this->InstanceID, 'BOTMESSAGE' => $data['message']['text'], 'USERID' => $data['message']['from']['id'], 'FIRSTNAME' => $data['message']['from']['first_name'], 'LASTNAME' => $data['message']['from']['last_name']], $actionPayload['parameters']));

                //Send debug after we executed
                $this->SendDebug('EXECUTED', sprintf('Action %s was executed by %s %s (%d)', $data['message']['text'], $data['message']['from']['first_name'], $data['message']['from']['last_name'], $data['message']['from']['id']), 0);

                //Notify user about our success
               // $this->SendMessageEx($this->Translate('Action executed!'), strval($data['message']['from']['id']));
                $found = true;
                break;
            }
        }

        //Notify user that we did not find a suitable action
        if (!$found) {
            $this->SendDebug('UNKNOWN', sprintf('Unknown Action %s was requested by %s %s (%d)', $data['message']['text'], $data['message']['from']['first_name'], $data['message']['from']['last_name'], $data['message']['from']['id']), 0);
            //$this->SendMessageEx($this->Translate('Unknown action!'), strval($data['message']['from']['id']));
        }
    }

    private function NameToUserID($NameOrChatID)
    {
        $recipients = json_decode($this->ReadPropertyString('AllowList'), true);
        foreach ($recipients as $recipient) {
            if ($recipient['Name'] == $NameOrChatID) {
                return $recipient['UserID'];
            }
        }
        return $NameOrChatID;
    }

    private function SetValueHTML($userid, $message, $first_name, $last_name, $user_name){
        $amount = 10;
        $header ='<body bgcolor="#a6caf0"><style type="text/css">table.liste { width: 100%; border-collapse: true;} table.liste td { border: 1px solid #444455; } table.liste th { border: 1px solid #444455; }</style>';
        $header.='<table border = "0" frame="box" class="liste">';
        $header.='<tr>';
        $header.='<th>' . $this->Translate('Date') . '</th>';
        $header.='<th>' . $this->Translate('Time') . '</th>';
        $header.='<th>' . $this->Translate('UserID') . '</th>';
        $header.='<th>' . $this->Translate('First Name') . '</th>';
        $header.='<th>' . $this->Translate('Last Name') . '</th>';
        $header.='<th>' . $this->Translate('User Name') . '</th>';
        $header.='<th>' . $this->Translate('Message') . '</th>';
        $header.='</tr>';
    
        $data ='<tr align="center"><td>'.date("d.m.Y").'</td>';
        $data.='<td>'.date("H:i").'</td>';
        $data.='<td>'.$userid.'</td>';
        $data.='<td>'.$first_name.'</td>';
        $data.='<td>'.$last_name.'</td>';
        $data.='<td>'.$user_name.'</td>';
        $data.='<td>'.$message.'</td>';
       
        $buffer = explode("</tr>",$this->ReadAttributeString("Buffer"),$amount);
        array_unshift($buffer, $data);
        $buffer = array_slice( $buffer, 0, $amount );	
        $string = implode("</tr>",$buffer);
        $this->WriteAttributeString("Buffer",$string);

        $this->SetValue('HTMLTable', $header . $string . "</table></body>");
    }
}