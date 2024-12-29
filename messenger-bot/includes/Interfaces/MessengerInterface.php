<?php
namespace MessengerBot\Interfaces;

interface MessengerInterface {
    // متدهای پایه
    public function getName(): string;
    public function initialize(): void;

    // متدهای ارسال پیام
    public function sendMessage(string $chatId, string $message, array $options = []): array;

    // متدهای مدیریت گروه
    public function getGroupInfo(string $groupId): array;
}
