<?php

use Mini\Entity\Entity;
use Mini\Entity\Behaviors\QueryAware;

class SerializableEntityStub extends Entity
{
    use QueryAware;

    public $table = 'users';

    public $definition = [
        'id' => 'pk',
        'name' => 'string',
        'is_draft' => 'boolean',
        'max_users_quantity' => 'integer',
        'address_geolocalization' => 'string:200',
        'address_street_name' => 'string:200',
        'address_number' => 'string:50',
        'owner_id' => 'integer|belongsTo:owners',
        'owner_will_go' => 'boolean'
    ];

    public $relations = [
        'owner' => [
            'class' => 'SimpleEntityStub',
            'field' => 'owner_id'
        ]
    ];

    public $prefixAsObject = ['address'];
}
