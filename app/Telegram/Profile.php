<?php

declare(strict_types=1);

namespace App\Telegram;

/** The Telegram user behind a session, as getSelf() describes it. */
final readonly class Profile
{
    public function __construct(
        public string $id,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $username,
    ) {}

    /** @param array<string, mixed> $self */
    public static function fromSelf(array $self): self
    {
        return new self(
            (string) ($self['id'] ?? ''),
            $self['first_name'] ?? null,
            $self['last_name'] ?? null,
            $self['username'] ?? null,
        );
    }

    /** @return array<string, string|null> columns of user_phones */
    public function toColumns(): array
    {
        return [
            'telegram_user_id' => $this->id,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'username' => $this->username,
        ];
    }
}
