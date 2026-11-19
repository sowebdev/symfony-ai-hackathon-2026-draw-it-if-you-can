<?php

namespace App\Tests\Unit\Ai;

use App\Ai\Role;
use PHPUnit\Framework\TestCase;

final class RoleTest extends TestCase
{
    public function testTheGuesserFallsBackOnTheDrawer(): void
    {
        self::assertSame(Role::Drawer, Role::Guesser->fallback());
    }

    public function testTheDrawerFollowsNobody(): void
    {
        self::assertNull(Role::Drawer->fallback());
    }

    public function testNoRoleIsItsOwnFallbackAndTheChainEnds(): void
    {
        foreach (Role::cases() as $role) {
            $seen = [];
            for ($current = $role; null !== $current; $current = $current->fallback()) {
                self::assertNotContains($current, $seen, 'A fallback never loops');
                $seen[] = $current;
            }
        }
    }
}
