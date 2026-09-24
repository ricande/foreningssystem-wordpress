<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

use InvalidArgumentException;

final class Membership
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $number,
        private readonly MembershipKind $kind,
        private readonly ?int $organizationId,
    ) {
        if (trim($this->number) === '') {
            throw new InvalidArgumentException('A membership needs a number.');
        }

        if ($this->kind === MembershipKind::Company && ($this->organizationId === null || $this->organizationId < 1)) {
            throw new InvalidArgumentException('A company membership belongs to an organization.');
        }

        if ($this->kind !== MembershipKind::Company && $this->organizationId !== null) {
            throw new InvalidArgumentException('Only a company membership belongs to an organization.');
        }
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function kind(): MembershipKind
    {
        return $this->kind;
    }

    public function organizationId(): ?int
    {
        return $this->organizationId;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->number, $this->kind, $this->organizationId);
    }
}
