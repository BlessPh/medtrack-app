<?php

namespace App\Shared\Enums;

enum InstitutionRole: string
{
    case Admin = 'INSTITUTION_ADMIN';
    case AcademicManager = 'ACADEMIC_MANAGER';
    case HospitalManager = 'HOSPITAL_MANAGER';
    case Supervisor = 'SUPERVISOR';
    case FinanceOfficer = 'FINANCE_OFFICER';
    case Student = 'STUDENT';
    case Member = 'MEMBER';
}
