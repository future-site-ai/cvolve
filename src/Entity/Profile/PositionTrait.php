<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use Doctrine\ORM\Mapping as ORM;

trait PositionTrait
{
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }
}
