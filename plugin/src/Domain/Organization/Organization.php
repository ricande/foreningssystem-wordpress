<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Organization;

use InvalidArgumentException;

final class Organization
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $name,
        private readonly ?OrganizationNumber $number,
        private readonly string $email,
        private readonly string $postalAddress,
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('An organization needs a name.');
        }

        if ($this->email !== '' && filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Email is not valid.');
        }
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function number(): ?OrganizationNumber
    {
        return $this->number;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function postalAddress(): string
    {
        return $this->postalAddress;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->name, $this->number, $this->email, $this->postalAddress);
    }
}
