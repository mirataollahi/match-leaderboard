<?php declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * ORM table for the `trophy_history` database table.
 */
class TrophyHistoryTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('trophy_history');
        $this->setEntityClass('App\Model\Entity\TrophyHistory');
        $this->setPrimaryKey(['user_id', 'match_id']);

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
        $validator->integer('user_id')->notEmptyString('user_id');
        $validator->notEmptyString('match_id')->maxLength('match_id', 64);
        $validator->integer('score_before')->notEmptyString('score_before');
        $validator->integer('score_after')->notEmptyString('score_after');
        $validator->integer('score_delta')->notEmptyString('score_delta');
        $validator->notEmptyString('reason')->maxLength('reason', 50);

        return $validator;
    }
}
