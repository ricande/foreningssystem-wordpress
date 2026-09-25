<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Access;

use InvalidArgumentException;

final class RoleCapabilitySetting
{
    /**
     * @param array<string, list<string>> $bundles
     */
    private function __construct(private readonly array $bundles)
    {
    }

    public static function defaults(): self
    {
        return new self(RoleBundles::defaults());
    }

    /**
     * @param array<string, mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        $bundles = self::defaults()->bundles;

        foreach (RoleBundles::roles() as $role) {
            if (! isset($stored[$role]) || ! is_array($stored[$role])) {
                continue;
            }

            $capabilities = [];

            foreach ($stored[$role] as $capability) {
                if (is_string($capability) && in_array($capability, Capabilities::all(), true)) {
                    $capabilities[] = $capability;
                }
            }

            $bundles[$role] = self::withNavigation($capabilities);
        }

        return new self($bundles);
    }

    /**
     * @return list<string>
     */
    public function capabilitiesFor(string $role): array
    {
        return $this->bundles[$role] ?? [];
    }

    public function grant(string $role, string $capability): self
    {
        $this->assertKnown($role, $capability);
        $capabilities = $this->capabilitiesFor($role);

        if (! in_array($capability, $capabilities, true)) {
            $capabilities[] = $capability;
        }

        $bundles = $this->bundles;
        $bundles[$role] = array_values($capabilities);

        return new self($bundles);
    }

    public function revoke(string $role, string $capability): self
    {
        $this->assertKnown($role, $capability);

        $bundles = $this->bundles;
        $bundles[$role] = array_values(array_filter(
            $this->capabilitiesFor($role),
            static fn (string $current): bool => $current !== $capability
        ));

        return new self($bundles);
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return $this->bundles;
    }

    /**
     * Menu access follows any real association capability. Older stored bundles
     * did not contain this navigation capability, and it is not a business permission.
     *
     * @param list<string> $capabilities
     * @return list<string>
     */
    private static function withNavigation(array $capabilities): array
    {
        $capabilities = array_values(array_unique($capabilities));

        if ($capabilities === [] || in_array(Capabilities::ACCESS_ASSOCIATION, $capabilities, true)) {
            return $capabilities;
        }

        $capabilities[] = Capabilities::ACCESS_ASSOCIATION;

        return $capabilities;
    }

    private function assertKnown(string $role, string $capability): void
    {
        if (! in_array($role, RoleBundles::roles(), true) || ! in_array($capability, Capabilities::all(), true)) {
            throw new InvalidArgumentException('Unknown association role or capability.');
        }
    }
}
