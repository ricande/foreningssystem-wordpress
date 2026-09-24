<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Person;

use InvalidArgumentException;

final class Person
{
    public const ANONYMOUS_FIRST_NAME = 'Anonym';

    public const ANONYMOUS_LAST_NAME = 'Medlem';

    public function __construct(
        private readonly ?int $id,
        private readonly string $firstName,
        private readonly string $lastName,
        private readonly string $email,
        private readonly PersonStatus $status,
        private readonly ?int $wordpressUserId,
        private readonly ?\Foreningssystem\Domain\Membership\AssociationDate $birthDate = null,
    ) {
        if ($this->firstName === '' || $this->lastName === '') {
            throw new InvalidArgumentException('A person needs a first and last name.');
        }

        if ($this->email !== '' && filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Email is not valid.');
        }
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function status(): PersonStatus
    {
        return $this->status;
    }

    public function wordpressUserId(): ?int
    {
        return $this->wordpressUserId;
    }

    public function birthDate(): ?\Foreningssystem\Domain\Membership\AssociationDate
    {
        return $this->birthDate;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->firstName, $this->lastName, $this->email, $this->status, $this->wordpressUserId, $this->birthDate);
    }

    public function withContact(string $firstName, string $lastName, string $email, ?\Foreningssystem\Domain\Membership\AssociationDate $birthDate): self
    {
        return new self($this->id, trim($firstName), trim($lastName), trim($email), $this->status, $this->wordpressUserId, $birthDate);
    }

    public function markedDeceased(): self
    {
        return new self($this->id, $this->firstName, $this->lastName, $this->email, PersonStatus::Deceased, $this->wordpressUserId, $this->birthDate);
    }

    public function anonymized(): self
    {
        return new self($this->id, self::ANONYMOUS_FIRST_NAME, self::ANONYMOUS_LAST_NAME, '', $this->status, null, null);
    }
}
