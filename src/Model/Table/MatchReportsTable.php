<?php declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * ORM table for the `match_reports` database table.
 */
class MatchReportsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('match_reports');
        $this->setEntityClass('App\Model\Entity\MatchReport');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created_at' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'className' => 'App\Model\Table\UsersTable',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->notEmptyString('request_id')
            ->maxLength('request_id', 64)
            ->add('request_id', 'unique', [
                'rule' => 'validateUnique',
                'provider' => 'table',
                'message' => 'Request already processed.',
            ]);

        $validator
            ->integer('user_id')
            ->notEmptyString('user_id');

        $validator
            ->notEmptyString('match_id')
            ->maxLength('match_id', 64);

        $validator
            ->notEmptyString('result')
            ->maxLength('result', 20)
            ->add('result', 'valid', [
                'rule' => ['inList', ['win', 'loss', 'draw']],
                'message' => 'Result must be win, loss, or draw.',
            ]);

        $validator
            ->integer('score_delta')
            ->notEmptyString('score_delta');

        $validator
            ->dateTime('reported_at')
            ->notEmptyDateTime('reported_at');

        return $validator;
    }
}
