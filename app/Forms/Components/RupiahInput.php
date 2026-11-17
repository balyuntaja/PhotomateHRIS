<?php

namespace App\Forms\Components;

use Filament\Forms\Components\TextInput;

class RupiahInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->rupiah();
    }
}
