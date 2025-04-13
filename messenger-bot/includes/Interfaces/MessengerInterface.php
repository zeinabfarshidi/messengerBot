<?php
namespace MessengerBot\Interfaces;

interface MessengerInterface {
    // Basic methods
    public function getName(): string;
    public function initialize(): void;

    // Message sending methods
    public function sendMessage(string $chatId, string $message, array $options = []): array;

    // Group management methods
    public function getGroupInfo(string $groupId): array;
}
