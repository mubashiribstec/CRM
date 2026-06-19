<?php

namespace App\Services;

use Horsefly\Applicant;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared primitives for the CRM workflow-transition methods in
 * CrmController. Each transition method previously repeated the same
 * "deactivate old note, create new note + uid, deactivate old history,
 * create new history + uid" sequence inline with minor variations; these
 * helpers extract that duplication without changing any behavior — every
 * call site passes the exact same where-clauses and attributes the
 * original inline code used.
 */
class ApplicantWorkflowService
{
    public function setApplicantFlags(int $applicantId, array $flags): void
    {
        Applicant::where('id', $applicantId)->update($flags);
    }

    /** @param class-string<Model> $modelClass */
    public function deactivate(string $modelClass, array $where, string $column = 'status', $value = 0): void
    {
        $modelClass::where($where)->update([$column => $value]);
    }

    /** @param class-string<Model> $modelClass */
    public function delete(string $modelClass, array $where): void
    {
        $modelClass::where($where)->delete();
    }

    /**
     * Create a record and stamp its uid column with md5(id), mirroring the
     * "save, then set the *_uid column from the new id, save again" pattern
     * used by every Crm/Quality/History/CV/Sale note in CrmController.
     *
     * @param class-string<Model> $modelClass
     */
    public function createWithUid(string $modelClass, array $attributes, string $uidColumn): Model
    {
        $record = new $modelClass();
        foreach ($attributes as $key => $value) {
            $record->{$key} = $value;
        }
        $record->save();

        $record->{$uidColumn} = md5((string) $record->id);
        $record->save();

        return $record;
    }
}
