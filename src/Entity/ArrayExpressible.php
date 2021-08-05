<?php

namespace App\Entity;

abstract class ArrayExpressible
{
    public function toArray()
    {

        return get_object_vars($this);
    }
}
