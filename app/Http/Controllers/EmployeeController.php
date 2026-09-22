<?php

namespace App\Http\Controllers;

/**
 * HR naming alias for the existing Faculty/Staff controller.
 *
 * The inherited actions deliberately operate on the Platform `faculties`
 * table, keeping one employee record per college across Academic and HR.
 */
class EmployeeController extends FacultyController
{
}
