<?php declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class MatchReportsTable extends Table
{
    /**
     * Initialize match report table configs
     *
     * @param array $config
     * @return void
     */
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
                    'updated_at' => 'always',
                ],
            ],
        ]);
        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'className' => 'App\Model\Table\UsersTable',
            'joinType' => 'INNER',
        ]);
    }

    /**
     * Validate match report columns before insert in database
     *
     * @param Validator $validator
     * @return Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        // Validate request_id for duplicate same request re-try
        $validator->notEmptyString('request_id')
            ->maxLength('request_id', 64)
            ->add('request_id', 'unique', [
                'rule' => 'validateUnique',
                'provider' => 'table',
                'message' => 'Request already processed',
            ]);

        // Validate user_id
        $validator->integer('user_id')
            ->notEmptyString('user_id');

        // Validate match_id
        $validator->notEmptyString('match_id')
            ->maxLength('match_id', 64);

        // Validate result
        $validator->notEmptyString('result')
            ->maxLength('result', 20)
            ->add('result', 'valid', [
                'rule' => ['inList', ['win', 'loss', 'draw']],
                'message' => 'Result must be win, loss, or draw.',
            ]);

        // Validate  score_delta
        $validator->integer('score_delta')
            ->notEmptyString('score_delta');

        // Validate  reported_at
        $validator->dateTime('reported_at')
            ->notEmptyDateTime('reported_at');

        return $validator;
    }

    /**
     * Validate each user have one match_id result
     * Validate user_id exists in users table
     *
     * @param RulesChecker $rules
     * @return RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        // Ensure unique user-match combination
        $rules->add(
            $rules->isUnique(
                ['user_id', 'match_id'],
                'This user has already submitted a report for this match.'
            )
        );

        // Ensure the user exists
        $rules->add(
            $rules->existsIn('user_id', 'Users'),
            ['errorField' => 'user_id', 'message' => 'User does not exist.']
        );

        // Ensure match_id exists in the matches table for future

        return $rules;
    }
}
