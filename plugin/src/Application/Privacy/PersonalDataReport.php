<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Privacy;

final class PersonalDataReport
{
    /**
     * @param list<ExportedPerson> $people
     */
    public function __construct(private readonly array $people)
    {
    }

    /**
     * @return list<ExportedPerson>
     */
    public function people(): array
    {
        return $this->people;
    }
}
