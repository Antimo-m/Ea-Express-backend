<?php

namespace App;

enum SenderType: string
{
    case Private = 'private';
    case Business = 'business';
    case OnlineShop = 'online_shop';
}
