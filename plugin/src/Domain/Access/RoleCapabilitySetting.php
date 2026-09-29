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

        foreach ($stored as $role => $values) {
            if (! is_string($role) || ! self::isCustomBoardSlug($role) || ! is_array($values)) {
                continue;
            }

            $capabilities = [];

            foreach ($values as $capability) {
                if (
                    is_string($capability)
                    && in_array($capability, [Capabilities::FINALIZE_MINUTES, Capabilities::PUBLISH_MINUTES], true)
                ) {
                    $capabilities[] = $capability;
                }
            }

            if ($capabilities === []) {
                continue;
            }

            $bundles[$role] = self::withNavigation($capabilities);
        }

        return new self($bundles);
    }

    public static function isCustomBoardSlug(string $role): bool
    {
        return strlen($role) <= 50 && preg_match('/^custom_[a-z0-9_]{1,43}$/', $role) === 1;
    }

    /**
     * @return list<string>
     */
    public function capabilitiesFor(string $role): array
    {
        return $this->bundles[$role] ?? [];
    }

    /**
     * Custom board-role slugs stored beside the four association roles.
     *
     * @return list<string>
     */
    public function extraSlugs(): array
    {
        $slugs = [];

        foreach (array_keys($this->bundles) as $role) {
            if (! in_array($role, RoleBundles::roles(), true)) {
                $slugs[] = $role;
            }
        }

        return $slugs;
    }

    public function grantsMinutes(string $role): bool
    {
        return self::listGrantsMinutes($this->capabilitiesFor($role));
    }

    public function withoutExtra(string $role): self
    {
        if (in_array($role, RoleBundles::roles(), true) || ! isset($this->bundles[$role])) {
            return $this;
        }

        $bundles = $this->bundles;
        unset($bundles[$role]);

        return new self($bundles);
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

        if (! in_array($role, RoleBundles::roles(), true)) {
            $bundles[$role] = self::withNavigation($bundles[$role]);
        }

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

        if (! in_array($role, RoleBundles::roles(), true) && ! self::listGrantsMinutes($bundles[$role])) {
            unset($bundles[$role]);
        }

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

    /**
     * @param list<string> $capabilities
     */
    private static function listGrantsMinutes(array $capabilities): bool
    {
        return in_array(Capabilities::FINALIZE_MINUTES, $capabilities, true)
            || in_array(Capabilities::PUBLISH_MINUTES, $capabilities, true);
    }

    private function assertKnown(string $role, string $capability): void
    {
        if (! in_array($capability, Capabilities::all(), true)) {
            throw new InvalidArgumentException('Unknown association role or capability.');
        }

        if (in_array($role, RoleBundles::roles(), true)) {
            return;
        }

        if (
            self::isCustomBoardSlug($role)
            && in_array($capability, [
                Capabilities::FINALIZE_MINUTES,
                Capabilities::PUBLISH_MINUTES,
                Capabilities::ACCESS_ASSOCIATION,
            ], true)
        ) {
            return;
        }

        throw new InvalidArgumentException('Unknown association role or capability.');
    }
}
