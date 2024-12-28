<?php
namespace MessengerBot\Interfaces;

interface MessengerInterface {
    // متدهای پایه
    public function getName(): string;
    public function initialize(): void;

    // متدهای ارسال پیام
    public function sendMessage(string $chatId, string $message, array $options = []): array;
    public function sendFile(string $chatId, string $filePath, string $caption = ''): array;

    // متدهای مدیریت گروه
    public function getGroupInfo(string $groupId): array;
}
