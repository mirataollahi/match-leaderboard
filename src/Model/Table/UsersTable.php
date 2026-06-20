<?php declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class UsersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('users');
        $this->setEntityClass('App\Model\Entity\User');
        $this->setPrimaryKey('id');
        $this->setDisplayField('name');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created_at' => 'new',
                    'updated_at' => 'always',
                ],
            ],
        ]);

        $this->hasMany('MatchReports', [
            'foreignKey' => 'user_id',
            'className' => 'App\Model\Table\MatchReportsTable',
        ]);

        $this->hasMany('TrophyHistory', [
            'foreignKey' => 'user_id',
            'className' => 'App\Model\Table\TrophyHistoryTable',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->notEmptyString('name')
            ->maxLength('name', 255)
            ->add('name', 'unique', [
                'rule' => 'validateUnique',
                'provider' => 'table',
                'message' => 'Username already taken.',
            ]);

        $validator
            ->integer('score')
            ->allowEmptyString('score'); // 0 is valid default

        return $validator;
    }
}
