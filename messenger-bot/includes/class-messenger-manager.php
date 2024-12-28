<?php
class MessengerManager {
    private $messengers = [];

    public function register_messenger($messenger) {
        if (method_exists($messenger, 'send_message')) {
            $this->messengers[] = $messenger;
            return true;
        }
        return false;
    }

    public function get_messengers() {
        return $this->messengers;
    }

    public function send_message($to, $message) {
        $results = [];
        foreach ($this->messengers as $messenger) {
            $results[] = $messenger->send_message($to, $message);
        }
        return $results;
    }
}
