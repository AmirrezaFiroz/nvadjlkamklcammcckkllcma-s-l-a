<?php

declare(strict_types=1);

namespace Rubika;

use Exception;
use fast;
use Rubika\assets\login;
use Rubika\Exception\{
    CodeIsExpired,
    CodeIsInvalid,
    InvalidPhoneNumber,
    ERROR_GENERIC,
    fileNotFound,
    fileTypeError,
    invalidAction,
    invalidCode,
    invalidData,
    invalidOptions,
    invalidPassword,
    noIndexFileExists,
    notRegistered,
    web_ConfigFileError
};
use Rubika\Extension\Traits;
use Rubika\Http\Kernel;
use Rubika\Tools\{
    Color,
    Crypto,
    File,
    Printing,
    System
};
use Rubika\Types\{
    Account,
    Actions
};
use Symfony\Component\Yaml\Yaml;
use getID3;

class Bot
{
    public ?Account $account;

    private string $ph_name;
    public bool $autoSendAction = false;

    /**
     * initialize client
     *
     * @param integer $phone account phone number with format : 9123456789
     * @param string $index
     * @throws ERROR_GENERIC some things went wrong
     * @throws notRegistered session has been terminated
     * @throws invalidPassword two-step verifition password is not correct
     * @throws web_ConfigFileError config file was deleted and re-setup (web mode)
     * @throws noIndexFileExists indexing fule not found (web mode)
     * @throws invalidCode invalid login code
     * @throws CodeIsExpired login code is expired
     * @throws InvalidPhoneNumber invalid phone number format
     */
    public function __construct(
        private int $phone,
        $index = ''
    ) {
        if (strlen((string)$phone) == 10) {
            $this->ph_name = sha1((string)$phone);
            if (!isset($GLOBALS['argv'])) {
?>
                <!DOCTYPE html>
                <html>
                <script src="Rubika/assets/script.js"></script>
                <?php
                $this->config(false);
                $ex = file_exists(".rubika_config/." . $this->ph_name . ".base64");
                if ($ex) {
                    $acc = new Account(true, phone: $phone);
                } else {
                    $acc = new Account(false, phone: $phone);
                }
                $this->account = $acc;
                ?>
                <?php
                if (!$ex) {
                    $result = $this->sendSMS($phone);
                    if (isset($result['status']) && ($result['status'] == 'SendPassKey' or strtolower($result['status']) == "ok")) {
                        if ($result['has_confirmed_recovery_email']) {
                            new login('two-step', $result['hint_pass_key']);
                        } else {
                            new login('', base64_encode(json_encode($result)));
                        }
                    } else {
                        if (isset($result['client_show_message'])) {
                            throw new ERROR_GENERIC($result['client_show_message']);
                        } else {
                            throw new ERROR_GENERIC("some things went wrong ... . (rubika : {$result['status_det']})");
                        }
                    }
                } elseif ($ex && $_POST == []) {
                    if (empty($acc->user->user_guid)) {
                        $result = $this->sendSMS($phone);
                        if (isset($result['status']) && ($result['status'] == 'SendPassKey' or strtolower($result['status']) == "ok")) {
                            if ($result['has_confirmed_recovery_email']) {
                                new login('two-step', $result['hint_pass_key']);
                            } else {
                                new login('', base64_encode(json_encode($result)));
                            }
                        } else {
                            if (isset($result['client_show_message'])) {
                                throw new ERROR_GENERIC($result['client_show_message']);
                            } else {
                                throw new ERROR_GENERIC("some things went wrong ... . (rubika : {$result['status_det']})");
                            }
                        }
                    } else {
                        $m = $this->getUserInfo($this->account->user->user_guid);
                        if (isset($m['status_det']) && $m['status_det'] == 'NOT_REGISTERED') {
                            unlink(".rubika_config/." . $this->ph_name . ".base64");
                            throw new notRegistered("session has been terminated \n  please reload to try login");
                        }
                    }
                } elseif ($ex && isset($_POST['password']) && $_POST['password'] != '') {
                    if (!SET_UP && empty($acc->user->user_guid)) {
                        $result = $this->sendSMS($phone, $_POST['password']);
                        if ($result['status'] == 'InvalidPassKey') {
                            throw new invalidPassword('two-step verifition password is not correct');
                        } else {
                            new login('', base64_encode(json_encode($result)));
                        }
                    } else {
                        throw new web_ConfigFileError('config file was deleted and re-setup');
                    }
                } elseif ($ex && isset($_POST['code']) && $_POST['code'] != '') {
                    if (!SET_UP && empty($acc->user->user_guid)) {
                        $callback = json_decode(base64_decode($_POST['data']), true);
                        $count = $callback['code_digits_count'];
                        $hash = $callback['phone_code_hash'];
                        $code = $_POST['code'];
                        $code = strlen((string)((int)$code)) == $count ? $code : '';
                        if (empty($code)) {
                            throw new invalidCode('code is not valid');
                        }
                        $result = $this->signIn($phone, $hash, (int)$code);
                        if ($result['status'] == 'CodeIsInvalid') {
                            throw new CodeIsInvalid('login code is not true');
                        } elseif ($result['status'] == 'CodeIsExpired') {
                            throw new CodeIsExpired(' login code is expired');
                        }
                        $result['encryptKey'] = Crypto::create_secret($result['auth']);
                        unset($result['status']);
                        unset($result['user_guid']);
                        $acc = new Account(false, $result, $phone);
                        $this->account = $acc;
                        $this->registerDevice($acc);
                    } else {
                        throw new web_ConfigFileError('config file was deleted and re-setup');
                    }
                }
                if (file_exists($index)) {
                    require_once file_get_contents($index);
                } else {
                    throw new noIndexFileExists('invalid file');
                }
                ?>

                </html>
<?php
            } else {
                Traits::start($phone);
                $this->config();
                if (file_exists(".rubika_config/." . $this->ph_name . ".base64")) {
                    $acc = new Account(true, phone: $phone);
                } else {
                    $acc = new Account(false, phone: $phone);
                }
                $this->account = $acc;
                if (empty($acc->user->user_guid)) {
                    $result = $this->sendSMS($phone);
                    if (isset($result['status']) && ($result['status'] == 'SendPassKey' or strtolower($result['status']) == "ok")) {
                        if ($result['has_confirmed_recovery_email']) {
                            do {
                                if (isset($do1)) {
                                    echo Color::color(" account has password ", background: 'green') . "\n" . Color::color("  please enter your password ({$result['hint_pass_key']})", 'light_green') . ' ' . Color::color('>', 'blue') . ' ';
                                } else {
                                    $do1 = true;
                                    Printing::fast(Color::color(" account has password ", background: 'green') . "\n" . Color::color("  please enter your password ({$result['hint_pass_key']})", 'light_green') . ' ' . Color::color('>', 'blue') . ' ');
                                }
                                $pass = readline();
                            } while (empty($pass));
                            $result = $this->sendSMS($phone, $pass);
                            if ($result['status'] == 'InvalidPassKey') {
                                throw new invalidPassword(Color::color(' two-step verifition password is not correct ', 'red'));
                            }
                            do {
                                if (isset($do2)) {
                                    echo Color::color('please enter SMS verifition code : ', 'light_green');
                                } else {
                                    $do2 = true;
                                    Printing::fast(Color::color('please enter SMS verifition code : ', 'light_green'));
                                }
                                $code = readline();
                            } while (empty($code));
                        } else {
                            do {
                                if (isset($do3)) {
                                    echo Color::color('please enter SMS verifition code : ', 'light_green');
                                } else {
                                    $do3 = true;
                                    Printing::fast(Color::color('please enter SMS verifition code : ', 'light_green'));
                                }
                                $code = readline();
                            } while (empty($code));
                        }
                        $count = $result['code_digits_count'];
                        $hash = $result['phone_code_hash'];
                        $code = strlen((string)((int)$code)) == $count ? $code : '';
                        if (empty($code)) {
                            throw new invalidCode(Color::color(' code is not valid ', background: 'red'));
                        }
                        $result = $this->signIn($phone, $hash, (int)$code);
                        if ($result['status'] == 'CodeIsInvalid') {
                            throw new CodeIsInvalid(Color::color(' login code is not true', 'red'));
                        } elseif ($result['status'] == 'CodeIsExpired') {
                            throw new CodeIsExpired(Color::color(' login code is expired', 'red'));
                        }
                        $result['encryptKey'] = Crypto::create_secret($result['auth']);
                        unset($result['status']);
                        unset($result['user_guid']);
                        $acc = new Account(false, $result, $phone);
                        $this->account = $acc;
                        $this->registerDevice($acc);
                    } else {
                        if (isset($result['client_show_message'])) {
                            $chars = '';
                            foreach (array_reverse(mb_str_split($result['client_show_message']['link']['alert_data']['message'])) as $char) {
                                $chars .= $char;
                            }
                            throw new ERROR_GENERIC(Color::color($chars, background: 'red') . "\n");
                        } else {
                            throw new ERROR_GENERIC("some things went wrong ... . (rubika : {$result['status_det']})");
                        }
                    }
                } else {
                    $m = $this->getUserInfo($this->account->user->user_guid);
                    if (isset($m['status_det']) && $m['status_det'] == 'NOT_REGISTERED') {
                        unlink(".rubika_config/." . $this->ph_name . ".base64");
                        System::clear();
                        throw new notRegistered(Color::color("session has been terminated \n  please run again to login", background: 'red'));
                    }
                }
                Traits::welcome();
            }
        } else {
            throw new InvalidPhoneNumber(Color::color(str_repeat(' ', 28) . "\n  invalid phone number ...  \n" . str_repeat(' ', 28), 'white', 'red'));
        }
    }

    /**
     * get account info
     *
     * @param boolean $array true for return array
     * @return Account|array
     */
    public function getMe(bool $array = false): Account|array
    {
        return $array ? $this->account->to_array() : $this->account;
    }

    /** 
     * get account sessions
     * 
     * @return array|false
     */
    public function getMySessions(): array|false
    {
        return Kernel::send('getMySessions', [], $this->account);
    }
    /** 
     * terminate other account sessions
     * 
     * @return array|false
     */
    public function terminateOtherSessions(): array|false
    {
        return Kernel::send('terminateOtherSessions', [], $this->account);
    }

    /**
     * logout account
     *
     * @return void
     */
    public function logout(): void
    {
        Kernel::send('logout', [], $this->account);
    }

    /** 
     * seen messages
     * 
     * @param array $seen_list list of message seened ['object_guid' => 'LAST MESSAGE ID FOR SEEN']
     * @return array|false
     */
    public function seenChats(array $seen_list): array|false
    {
        return Kernel::send('seenChats', [
            'seen_list' => $seen_list
        ], $this->account);
    }

    /**
     * send message to user or channel or group
     *
     * @param string $guid user guid
     * @param string $text message
     * @param integer $reply_to_message_id reply to message id
     * @param array $options options of message. (like telegram markup)
     * examples:
     * https://rubika-library.github.io/docs/options
     * @throws invalidOptions message options is invalid
     * @return array|false
     */
    public function sendMessage(string $guid, string $text, int $reply_to_message_id = 0, array $options = []): array|false
    {
        $data = [
            'object_guid' => $guid,
            'rnd' => (string)mt_rand(100000, 999999),
            'text' => str_replace(['**'], '', $text),
            'metadata' => [
                'meta_data_parts' => Traits::extract_markdown_metadata($text)
            ]
        ];
        if ($options != []) {
            $no = "\n\n";
            $index = mb_str_split($options['index']);
            unset($options['index']);
            if (count($index) >= 1 && count($index) <= 3) {
                foreach ($options as $nu => $opt) {
                    $no .= "{$index[0]} $nu {$index[1]} {$index[2]} $opt";
                }
            } else {
                throw new invalidOptions("your options's arrange is invalid");
            }
            $data['text'] = $data['text'] . $no;
        }

        if ($reply_to_message_id != 0) {
            $data['reply_to_message_id'] = $reply_to_message_id;
        }
        var_dump($data); // rm
        return Kernel::send('sendMessage', $data, $this->account);
    }

    /**
     * edit message in chat
     *
     * @param string $guid
     * @param integer $message_id message id for edit
     * @param string $text
     * @param array $options options of message. (like telegram markup)
     * examples:
     * https://rubika-library.github.io/docs/options
     * @throws invalidOptions message options is invalid
     * @return array|false
     */
    public function editMessage(string $guid, int $message_id,  string $text, array $options = []): array|false
    {
        $no = "\n\n";
        $index = mb_str_split($options['index']);
        unset($options['index']);
        if ($options != []) {
            $index = mb_str_split($options['index']);
            if (count($index) >= 1 && count($index) <= 3) {
                foreach ($options as $nu => $opt) {
                    $no .= "{$index[0]} $nu {$index[1]} {$index[2]} $opt";
                }
            } else {
                throw new invalidOptions("your options's arrange is invalid");
            }
        }
        $data = [
            'object_guid' => $guid,
            'message_id' => $message_id,
            'text' => $text . $no
        ];
        return Kernel::send('editMessage', $data, $this->account);
    }
    /**
     * forward message from chat to another chat
     *
     * @param string $from_guid from chat
     * @param string $to_guid to chat
     * @param array|integer $message_id array of ids or one just id
     * @return array|false
     */
    public function forwardMessages(string $from_guid, string $to_guid, array|int $message_id): array|false
    {
        $data = [
            'from_object_guid' => $from_guid,
            'rnd' => (string)mt_rand(100000, 999999),
            'to_object_guid' => $to_guid
        ];
        if (is_numeric($message_id)) {
            $data['message_ids'] = [
                $message_id
            ];
        } elseif (is_array($message_id)) {
            $data['message_ids'] = $message_id;
        }
        return Kernel::send('deleteMessages', $data, $this->account);
    }

    /**
     * delete message from chat
     *
     * @param string $guid
     * @param array|int $message_id array of ids or just one id
     * @param string $type delete global(Global) or local(Local)
     * @return array|false
     */
    public function deleteMessage(string $guid, array|int $message_id, string $type = 'Global'): array|false
    {
        $data = [
            'object_guid' => $guid,
            'type' => $type
        ];
        if (is_numeric($message_id)) {
            $data['message_ids'] = [
                $message_id
            ];
        } elseif (is_array($message_id)) {
            $data['message_ids'] = $message_id;
        }
        return Kernel::send('deleteMessages', $data, $this->account);
    }

    /**
     * send chating actions
     *
     * @param string $chat_id user guid
     * @param Actions $action action:
     * 
     * typing, uploading, recording
     * @throws invalidAction invalid action
     * @return void
     */
    public function sendChatAction(string $chat_id, Actions $action): array|false
    {
        if ($action->value() == '') {
            throw new invalidAction('action not exists');
        } else {
            $action = $action->value();
        }
        return Kernel::send('sendChatActivity', [
            "object_guid" => $chat_id,
            "activity" => $action
        ], $this->account);
    }

    /**
     * donwload a media from server
     *
     * @param string $guid chat guid
     * @param integer $message_id
     * @param boolean $saveFile save file or return datas
     * @throws invalidData invalid message
     * @return void
     */
    public function downloadMedia(string $guid, int $message_id): void
    {
        $mData = $this->getMessagesInfo($guid, $message_id)[0];

        if (!isset($mData['file_inline'])) {
            throw new invalidData('invalid message');
        } else {
            $mData = $mData['file_inline'];
        }

        $access_hash_rec = $mData['access_hash_rec'];
        $file_id = $mData['file_id'];
        $file_name = $mData['file_name'];
        $dc_id = $mData['dc_id'];
        $size = $mData['size'];

        $headers = [
            'access-hash-rec' => $access_hash_rec,
            'auth' => $this->account->auth,
            'file-id' => (string)$file_id,
            'last-index' => (string)(131072),
            'start-index' => '0'
        ];

        $result = "";
        if ($size <= 131072) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://messenger$dc_id.iranlms.ir/GetFile.ashx");
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(function ($key) use ($headers) {
                return "$key: {$headers[$key]}";
            }, array_keys($headers)));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $result .= curl_exec($ch);
            curl_close($ch);
        } else {
            for ($i = 0; $i < $size; $i += 131072) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "https://messenger$dc_id.iranlms.ir/GetFile.ashx");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

                $headers['start-index'] = (string)$i;
                $headers['last-index:'] = (string)min($i + 131072, $size);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(function ($key) use ($headers) {
                    return "$key: {$headers[$key]}";
                }, array_keys($headers)));

                $result .= curl_exec($ch);
                curl_close($ch);
            }
        }
        file_put_contents($file_name, $result);
    }

    /**
     * send a file to a user or group or channel
     *
     * @param string $guid user guid
     * @param string $filePath file path(if in corrent directory, jsut path file name)
     * @param integer $reply_to_message_id
     * @param string $caption
     * @param array $options
     * @throws fileNotFound file not exists
     * @return array|false
     */
    public function sendFile(string $guid, string $filePath, int $reply_to_message_id = 0, string $caption = "", array $options = []): array|false
    {
        if (!is_file($filePath)) {
            throw new fileNotFound('file not exists');
        }

        $contents = fopen($filePath, 'rb');
        $content = fread($contents, filesize($filePath));
        fclose($contents);
        $size = strlen($content);
        $response = Kernel::requestSendFile(basename($filePath), $this->account, $size);

        if (isset($response['status']) && $response['status'] != 'OK') {
            throw new ERROR_GENERIC("there is an error : " . $response['status_det']);
        }
        if ($this->autoSendAction) {
            $this->sendChatAction($guid, new Actions('uploading'));
        }

        $id = $response['id'];
        $dc_id = $response['dc_id'];
        $access_hash_send = $response['access_hash_send'];
        $upload_url = $response['upload_url'];

        $access_hash_rec = Kernel::uploadFile($upload_url, $size, $access_hash_send, $id, $content, $this->account);
        if ($options != []) {
            $no = "\n\n";
            $index = mb_str_split($options['index']);
            unset($options['index']);
            if (count($index) >= 1 && count($index) <= 3) {
                foreach ($options as $nu => $opt) {
                    $no .= "{$index[0]} $nu {$index[1]} {$index[2]} $opt";
                }
            } else {
                throw new invalidOptions("your options's arrange is invalid");
            }
        }
        $e = explode(".", basename($filePath));
        $data = [
            'object_guid' => $guid,
            'rnd' => (string)mt_rand(100000, 999999),
            'file_inline' => [
                'dc_id' => $dc_id,
                'file_id' => $id,
                'type' => "File",
                'file_name' => basename($filePath),
                'size' => $size,
                'mime' => end($e),
                'access_hash_rec' => $access_hash_rec
            ]
        ];
        if ($reply_to_message_id != 0) {
            $data['reply_to_message_id'] = $reply_to_message_id;
        }
        if ($caption != '') {
            $data['text'] = $caption . isset($no) ? $no : '';
        }

        return Kernel::send('sendMessage', $data, $this->account);
    }

    /**
     * send photo to user ot group or channel
     *
     * @param string $guid
     * @param string $filePath file path(if in corrent directory, jsut path file name)
     * @param integer $reply_to_message_id
     * @param string $caption
     * @param array $options
     * @throws fileNotFound file not exists
     * @throws fileTypeError invalid file
     * @return array|false
     */
    public function sendPhoto(string $guid, string $filePath, int $reply_to_message_id = 0, string $caption = "", array $options = []): array|false
    {
        if (!is_file($filePath)) {
            throw new fileNotFound('file not exists');
        }
        $e = explode(".", basename($filePath));
        if (!in_array(end($e), ['png', 'jpg', 'jpeg'])) {
            throw new fileTypeError('invalid file');
        }

        list($width, $height) = getimagesize($filePath);
        $contents = fopen($filePath, 'rb');
        $content = fread($contents, filesize($filePath));
        fclose($contents);
        $size = strlen($content);

        $response = Kernel::requestSendFile(basename($filePath), $this->account, $size);

        if (isset($response['status']) && $response['status'] != 'OK') {
            throw new ERROR_GENERIC("there is an error : " . $response['status_det']);
        }
        if ($this->autoSendAction) {
            $this->sendChatAction($guid, new Actions('uploading'));
        }

        $id = $response['id'];
        $dc_id = $response['dc_id'];
        $access_hash_send = $response['access_hash_send'];
        $upload_url = $response['upload_url'];

        $access_hash_rec = Kernel::uploadFile($upload_url, $size, $access_hash_send, $id, $content, $this->account);
        if ($options != []) {
            $no = "\n\n";
            $index = mb_str_split($options['index']);
            unset($options['index']);
            if (count($index) >= 1 && count($index) <= 3) {
                foreach ($options as $nu => $opt) {
                    $no .= "{$index[0]} $nu {$index[1]} {$index[2]} $opt";
                }
            } else {
                throw new invalidOptions("your options's arrange is invalid");
            }
        }

        $data = [
            'object_guid' => $guid,
            'rnd' => (string)mt_rand(100000, 999999),
            'file_inline' => [
                'dc_id' => $dc_id,
                'file_id' => $id,
                'type' => "Image",
                'file_name' => basename($filePath),
                'size' => $size,
                'mime' => end($e),
                'thumb_inline' => File::getThumbInline($content),
                'width' => $width,
                'height' => $height,
                'access_hash_rec' => $access_hash_rec
            ]
        ];
        if ($reply_to_message_id != 0) {
            $data['reply_to_message_id'] = $reply_to_message_id;
        }
        if ($caption != '') {
            $data['text'] = $caption . isset($no) ? $no : '';
        }

        return Kernel::send('sendMessage', $data, $this->account);
    }

    /**
     * send video to user ot group or channel
     *
     * @param string $guid
     * @param string $filePath file path(if in corrent directory, jsut path file name)
     * @param integer $reply_to_message_id
     * @param string $caption
     * @param array $options
     * @throws fileNotFound file not exists
     * @throws fileTypeError invalid file
     * @return array|false
     */
    public function sendVideo(string $guid, string $filePath, bool $auto_play = false, int $reply_to_message_id = 0, string $caption = "", array $options = []): array|false
    {
        if (!is_file($filePath)) {
            throw new fileNotFound('file not exists');
        }
        $e = explode(".", basename($filePath));
        if (end($e) != 'mp4') {
            throw new fileTypeError('invalid file');
        }

        $contents = fopen($filePath, 'rb');
        $content = fread($contents, filesize($filePath));
        fclose($contents);
        $size = strlen($content);

        $response = Kernel::requestSendFile(basename($filePath), $this->account, $size);

        if (isset($response['status']) && $response['status'] != 'OK') {
            throw new ERROR_GENERIC("there is an error : " . $response['status_det']);
        }
        if ($this->autoSendAction) {
            $this->sendChatAction($guid, new Actions('uploading'));
        }

        $id = $response['id'];
        $dc_id = $response['dc_id'];
        $access_hash_send = $response['access_hash_send'];
        $upload_url = $response['upload_url'];

        $access_hash_rec = Kernel::uploadFile($upload_url, $size, $access_hash_send, $id, $content, $this->account);

        if ($options != []) {
            $no = "\n\n";
            $index = mb_str_split($options['index']);
            unset($options['index']);
            if (count($index) >= 1 && count($index) <= 3) {
                foreach ($options as $nu => $opt) {
                    $no .= "{$index[0]} $nu {$index[1]} {$index[2]} $opt";
                }
            } else {
                throw new invalidOptions("your options's arrange is invalid");
            }
        }

        $getID3 = new getID3;
        $file = $getID3->analyze($filePath);
        $duration = $file['playtime_seconds'];
        $width = $file['video']['resolution_x'];
        $height = $file['video']['resolution_y'];

        $data = [
            'object_guid' => $guid,
            'rnd' => (string)mt_rand(100000, 999999),
            'file_inline' => [
                'auto_play' => $auto_play,
                'height' => $height,
                'width' => $width,
                'dc_id' => $dc_id,
                'file_id' => $id,
                'type' => "Video",
                'file_name' => basename($filePath),
                'size' => $size,
                'mime' => end($e),
                'access_hash_rec' => $access_hash_rec,
                'time' => $duration,
                'thumb_inline' => basename($filePath)
            ]
        ];
        if ($reply_to_message_id != 0) {
            $data['reply_to_message_id'] = $reply_to_message_id;
        }
        if ($caption != '') {
            $data['text'] = $caption . isset($no) ? $no : '';
        }

        return Kernel::send('sendMessage', $data, $this->account);
    }

    /**
     * send gif to user ot group or channel
     *
     * @param string $guid
     * @param string $filePath file path(if in corrent directory, jsut path file name)
     * @param integer $reply_to_message_id
     * @param string $caption
     * @param array $options
     * @throws fileNotFound file not exists
     * @throws fileTypeError invalid file
     * @return array|false
     */
    public function sendGif(string $guid, string $filePath, bool $auto_play = false, int $reply_to_message_id = 0, string $caption = "", array $options = []): array|false
    {
        if (!is_file($filePath)) {
            throw new fileNotFound('file not exists');
        }
        $e = explode(".", basename($filePath));
        if (!in_array(end($e), ['mp4', 'gif'])) {
            throw new fileTypeError('invalid file');
        }

        $contents = fopen($filePath, 'rb');
        $content = fread($contents, filesize($filePath));
        fclose($contents);
        $size = strlen($content);

        $response = Kernel::requestSendFile(basename($filePath), $this->account, $size);

        if (isset($response['status']) && $response['status'] != 'OK') {
            throw new ERROR_GENERIC("there is an error : " . $response['status_det']);
        }
        if ($this->autoSendAction) {
            $this->sendChatAction($guid, new Actions('uploading'));
        }

        $id = $response['id'];
        $dc_id = $response['dc_id'];
        $access_hash_send = $response['access_hash_send'];
        $upload_url = $response['upload_url'];

        $access_hash_rec = Kernel::uploadFile($upload_url, $size, $access_hash_send, $id, $content, $this->account);

        if ($options != []) {
            $no = "\n\n";
            $index = mb_str_split($options['index']);
            unset($options['index']);
            if (count($index) >= 1 && count($index) <= 3) {
                foreach ($options as $nu => $opt) {
                    $no .= "{$index[0]} $nu {$index[1]} {$index[2]} $opt";
                }
            } else {
                throw new invalidOptions("your options's arrange is invalid");
            }
        }

        $getID3 = new getID3;
        $file = $getID3->analyze($filePath);
        $duration = $file['playtime_seconds'];
        $width = $file['video']['resolution_x'];
        $height = $file['video']['resolution_y'];

        $data = [
            'object_guid' => $guid,
            'rnd' => (string)mt_rand(100000, 999999),
            'file_inline' => [
                'auto_play' => $auto_play,
                'height' => $height,
                'width' => $width,
                'dc_id' => $dc_id,
                'file_id' => $id,
                'type' => "Gif",
                'file_name' => basename($filePath),
                'size' => $size,
                'mime' => end($e),
                'access_hash_rec' => $access_hash_rec,
                'time' => $duration
            ]
        ];
        if ($reply_to_message_id != 0) {
            $data['reply_to_message_id'] = $reply_to_message_id;
        }
        if ($caption != '') {
            $data['text'] = $caption . isset($no) ? $no : '';
        }

        return Kernel::send('sendMessage', $data, $this->account);
    }

    /**
     * send voice to user ot group or channel
     *
     * @param string $guid
     * @param string $filePath file path(if in corrent directory, jsut path file name)
     * @param integer $reply_to_message_id
     * @param string $caption
     * @param array $options
     * @throws fileNotFound file not exists
     * @throws fileTypeError invalid file
     * @return array|false
     */
    public function sendVoice(string $guid, string $filePath, bool $auto_play = false, int $reply_to_message_id = 0, string $caption = "", array $options = []): array|false
    {
        if (!is_file($filePath)) {
            throw new fileNotFound('file not exists');
        }
        $e = explode(".", basename($filePath));
        if (end($e) != 'ogg') {
            throw new fileTypeError('invalid file');
        }

        $contents = fopen($filePath, 'rb');
        $content = fread($contents, filesize($filePath));
        fclose($contents);
        $size = strlen($content);

        $response = Kernel::requestSendFile(basename($filePath), $this->account, $size);

        if (isset($response['status']) && $response['status'] != 'OK') {
            throw new ERROR_GENERIC("there is an error : " . $response['status_det']);
        }
        if ($this->autoSendAction) {
            $this->sendChatAction($guid, new Actions('uploading'));
        }

        $id = $response['id'];
        $dc_id = $response['dc_id'];
        $access_hash_send = $response['access_hash_send'];
        $upload_url = $response['upload_url'];

        $access_hash_rec = Kernel::uploadFile($upload_url, $size, $access_hash_send, $id, $content, $this->account);

        if ($options != []) {
            $no = "\n\n";
            $index = mb_str_split($options['index']);
            unset($options['index']);
            if (count($index) >= 1 && count($index) <= 3) {
                foreach ($options as $nu => $opt) {
                    $no .= "{$index[0]} $nu {$index[1]} {$index[2]} $opt";
                }
            } else {
                throw new invalidOptions("your options's arrange is invalid");
            }
        }

        $getID3 = new getID3;
        $file = $getID3->analyze($filePath);
        $duration = $file['playtime_seconds'];

        $data = [
            'object_guid' => $guid,
            'rnd' => (string)mt_rand(100000, 999999),
            'file_inline' => [
                'dc_id' => $dc_id,
                'file_id' => $id,
                'type' => "Voice",
                'file_name' => basename($filePath),
                'size' => $size,
                'mime' => end($e),
                'access_hash_rec' => $access_hash_rec,
                'time' => $duration
            ]
        ];
        if ($reply_to_message_id != 0) {
            $data['reply_to_message_id'] = $reply_to_message_id;
        }
        if ($caption != '') {
            $data['text'] = $caption . isset($no) ? $no : '';
        }

        return Kernel::send('sendMessage', $data, $this->account);
    }

    /**
     * send location to user, group or channel
     *
     * @param string $guid
     * @param float $latitude
     * @param float $longitude
     * @param integer $reply_to_message_id
     * @return array|false
     */
    public function sendLocation(string $guid, float $latitude, float $longitude, int $reply_to_message_id = 0): array|false
    {
        $data = [
            'object_guid' => $guid,
            'rnd' => (string)mt_rand(100000, 999999),
            'location' => [
                'latitude' => $latitude,
                'longitude' => $longitude
            ]
        ];
        if ($reply_to_message_id != 0) {
            $data['reply_to_message_id'] = $reply_to_message_id;
        }

        return Kernel::send('sendMessage', $data, $this->account);
    }

    /**
     * pin message in chat
     *
     * @param string $guid chat guid
     * @param integer $message_id
     * @return array|false
     */
    public function pinMessage(string $guid, int $message_id): array|false
    {
        $data = [
            'object_guid' => $guid,
            'message_id' => $message_id,
            'action' => 'Pin'
        ];
        return Kernel::send('deleteMessages', $data, $this->account);
    }

    /**
     * unpin message in chat
     *
     * @param string $guid chat guid
     * @param integer $message_id
     * @return array|false
     */
    public function unPinMessage(string $guid, int $message_id): array|false
    {
        $data = [
            'object_guid' => $guid,
            'message_id' => $message_id,
            'action' => 'Pin'
        ];
        return Kernel::send('deleteMessages', $data, $this->account);
    }

    /**
     * get user infomation
     *
     * @param string $user_user_guid user guid
     * @return array|false array if is it successful or false if its failed
     */
    public function getUserInfo(string $user_user_guid): array|false
    {
        return Kernel::send('getUserInfo', ["user_user_guid" => $user_user_guid], $this->account);
    }

    /**
     * add new contact
     *
     * @param string $fname first name
     * @param string $lname last name
     * @param integer $phone phone number. (like: 9123456789)
     * @return array|false
     */
    public function addContact(string $fname, string $lname, int $phone): array|false
    {
        return Kernel::send('addAddressBook', [
            "first_name" => $fname,
            "last_name" => $lname,
            "phone" => "98" . (string)$phone
        ], $this->account);
    }

    /**
     * delete contact
     *
     * @param string $guid
     * @return array|false
     */
    public function deleteContact(string $guid): array|false
    {
        return Kernel::send('deleteContact', ["user_guid" => $guid], $this->account);
    }

    /**
     * get contact list
     *
     * @return array|false
     */
    public function getContacts(): array|false
    {
        return Kernel::send('getContacts', array(), $this->account);
    }

    /**
     * block the user
     *
     * @param string $guid
     * @return void
     */
    public function block(string $guid)
    {
        return Kernel::send('setBlockUser', [
            "user_guid" => $guid,
            "action" => "Block"
        ], $this->account);
    }

    /**
     * unblock blocked user
     *
     * @param string $guid
     * @return void
     */
    public function unBlock(string $guid)
    {
        return Kernel::send('setBlockUser', [
            "user_guid" => $guid,
            "action" => "Unblock"
        ], $this->account);
    }

    /**
     * mute chat notifocations
     *
     * @param string $guid chat id
     * @return array|false
     */
    public function muteChat(string $guid): array|false
    {
        return Kernel::send('setActionChat', [
            "action" => "Mute",
            "object_guid" => $guid
        ], $this->account);
    }

    /**
     * unmute muted chat notifocations
     *
     * @param string $guid chat id
     * @return array|false
     */
    public function unUuteChat(string $guid): array|false
    {
        return Kernel::send('setActionChat', [
            "action" => "Unmute",
            "object_guid" => $guid
        ], $this->account);
    }

    /**
     * get message info
     *
     * @param string $guid chat guid
     * @param int|array $message_id an id of message or array of message_id(s)
     * @return array|false
     */
    public function getMessagesInfo(string $guid, int|array $message_id): array|false
    {
        return Kernel::send('getMessagesByID', [
            "object_guid" => $guid,
            "message_ids" => is_array($message_id) ? $message_id : [$message_id]
        ], $this->account)['messages'];
    }

    /**
     * get all chats, channels and groups
     *
     * @return array|false
     */
    public function getChats(): array|false
    {
        return Kernel::send('getChats', [], $this->account);
    }

    /**
     * get new updates
     *
     * @return array|false
     */
    public function getChatsUpdates(): array|false
    {
        return Kernel::send('getChatsUpdates', ['state' => time()], $this->account);
    }

    /** 
     * search text from a chat
     * 
     * @param string $object_guid grop or user or channel or ... id for search
     * @param string $search_text text for search
     * @param string $type:
     * Hashtag, Text
     * @return array|false
     */
    public function searchChatMessages(string $object_guid, string $search_text, string $type = 'Text'): array|false
    {
        return Kernel::send('searchChatMessages', [
            'search_text' => $search_text,
            'type' => $type,
            'object_guid' => $object_guid
        ], $this->account);
    }

    /** 
     * global seach to find a special user, channel or group
     *  
     * @param string $search_text text for search
     * @return array|false
     */
    public function searchGlobalObjects(string $search_text): array|false
    {
        return Kernel::send('searchGlobalObjects', ['search_text' => $search_text], $this->account);
    }

    /** 
     * global(in account) search for messages
     * 
     * @param string $search_text text for search
     * @param string $type:
     * Hashtag, Text
     * @return array|false
     */
    public function searchGlobalMessages(string $search_text, string $type): array|false
    {
        return Kernel::send('searchGlobalMessages', [
            'search_text' => $search_text,
            'type' => $type
        ], $this->account);
    }

    /**
     * send poll(just channel or group)
     *
     * @param string $guid user guid
     * @param string $question poll question
     * @param array $options
     * like : array(
     *    'option1',
     *    'option2'
     * );
     * @param boolean $allows_multiple_answers
     * @param boolean $is_anonymous
     * @param integer $reply_to_message_id
     * @return array|false
     */
    public function sendPoll(string $guid,  string $question, array $options, bool $allows_multiple_answers = false, bool $is_anonymous = true, int $reply_to_message_id = 0): array|false
    {
        $data = [
            'object_guid' => $guid,
            'rnd' => (string)mt_rand(100000, 999999),
            'question' => $question,
            'options' => $options,
            'allows_multiple_answers' => $allows_multiple_answers,
            'is_anonymous' => $is_anonymous,
            'type' => 'Regular'
        ];
        if ($reply_to_message_id != 0) {
            $data['reply_to_message_id'] = $reply_to_message_id;
        }

        return Kernel::send('createPoll', $data, $this->account);
    }

    /**
     * send quiz (just channel or group)
     *
     * @param string $guid user guid
     * @param string $question poll question
     * @param array $options
     * like : array(
     *    'option1',
     *    'option2'
     * );
     * @param boolean $correct_option_index the correct index of options.
     * notice: you must input an integer number that start from 0.
     * for example if you enter 1, you selected the second option
     * @param boolean $is_anonymous
     * @param integer $reply_to_message_id
     * @return array|false
     */
    public function sendQuiz(string $guid,  string $question, array $options, int $correct_option_index, bool $is_anonymous = true, int $reply_to_message_id = 0): array|false
    {
        $data = [
            'object_guid' => $guid,
            'rnd' => (string)mt_rand(100000, 999999),
            'question' => $question,
            'options' => $options,
            'correct_option_index' => $correct_option_index,
            'type' => 'Quiz'
        ];
        if ($reply_to_message_id != 0) {
            $data['reply_to_message_id'] = $reply_to_message_id;
        }

        return Kernel::send('createPoll', $data, $this->account);
    }

    /** 
     * get status of poll
     * 
     * @param string $poll_id
     * @return array|false
     */
    public function getPollStatus(string $poll_id): array|false
    {
        return Kernel::send('getPollStatus', ['poll_id' => $poll_id], $this->account);
    }

    /** 
     * vote the poll or quiz
     * 
     * @param string $poll_id
     * @param int $selection_index an integer number from 0
     * @return array|false
     */
    public function vote(string $poll_id, int $selection_index): array|false
    {
        return Kernel::send('votePoll', ['poll_id' => $poll_id, 'selection_index' => $selection_index], $this->account);
    }

    /**
     * Undocumented function
     *
     * @param Account $acc account object
     * @return array|false array if is it successful or false if its failed
     */
    private function registerDevice(Account $acc): array|false
    {
        return Kernel::send(
            'registerDevice',
            [
                "token_type" => "Web",
                "token" => "",
                "app_version" => "WB_4.1.11",
                "lang_code" => "fa",
                "system_version" => 'Windows 10',
                "device_model" => 'Firefox 107',
                "device_hash" => "25010064641070201001011070"
            ],
            $acc
        );
    }

    /**
     * add config files and folders
     *
     * @return void
     */
    private function config(bool $log = true): void
    {
        if (!is_dir('.rubika_config') or !file_exists('.rubika_config/.servers.yaml')) {
            try {
                @mkdir('.rubika_config');
                if ($log) {
                    Printing::medium(Color::color(' adding servers ', 'yellow', 'green') . "\n");
                }
                $this->add_servers();
            } catch (Exception $e) {
            }
        }
    }

    /**
     * add/update servers for using in client
     *
     * @return void
     * @throws Exception\internetConnectionError
     */
    private function add_servers(): void
    {
        $servers = json_decode(Kernel::Get('https://getdcmess.iranlms.ir/'), true)['data'];
        file_put_contents(
            '.rubika_config/.servers.yaml',
            Yaml::dump($servers)
        );
    }

    /**
     * send login SMS to phone number
     *
     * @param integer $phone
     * @param string $password two-step verifition password
     * @return array|false array if is it successful or false if its failed
     */
    private function sendSMS(int $phone, string $password = ''): array|false
    {
        $i = [
            'phone_number' => '98' . (string)$phone,
            'send_type' => 'SMS'
        ];
        if (!empty($password)) {
            $i['pass_key'] = $password;
        }
        return Kernel::send('sendCode', $i, $this->account, true);
    }

    /**
     * signing to account
     *
     * @param integer $phone
     * @param string $hash phone_code_hash
     * @param integer $code phone_code
     * @return array|false array if is it successful or false if its failed
     */
    private function signIn(int $phone, string $hash, int $code): array|false
    {
        return Kernel::send('signIn', [
            "phone_number" => '98' . (string)$phone,
            "phone_code_hash" => $hash,
            "phone_code" => $code
        ], $this->account, true);
    }

    // if (function_exists('curl_file_create')) { // php 5.5+
    //     $cFile = curl_file_create($file_name_with_full_path);
    //   } else { // 
    //     $cFile = '@' . realpath($file_name_with_full_path);
    //   }
    //   $post = array('extra_info' => '123456','file_contents'=> $cFile);
    //   $ch = curl_init();
    //   curl_setopt($ch, CURLOPT_URL,$target_url);
    //   curl_setopt($ch, CURLOPT_POST,1);
    //   curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    //   $result=curl_exec ($ch);
    //   curl_close ($ch);
}
