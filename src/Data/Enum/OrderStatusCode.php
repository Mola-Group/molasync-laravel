<?php

namespace Molaprise\Molasync\Data\Enum;

enum OrderStatusCode: string
{
    case Accepted = 'accepted';
    case Shipped = 'shipped';
    case Deleted = 'deleted';
    case NotFound = 'notFound';
    case Rejected = 'rejected';
    case Invoiced = 'invoiced';
}
