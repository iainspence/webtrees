<?php
/**
 * webtrees: online genealogy
 * Copyright (C) 2019 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Fisharebest\Webtrees\Statistics\Repository;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Database;
use Fisharebest\Webtrees\Functions\FunctionsDate;
use Fisharebest\Webtrees\Functions\FunctionsPrintLists;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Statistics\Google\ChartAge;
use Fisharebest\Webtrees\Statistics\Google\ChartBirth;
use Fisharebest\Webtrees\Statistics\Google\ChartCommonGiven;
use Fisharebest\Webtrees\Statistics\Google\ChartCommonSurname;
use Fisharebest\Webtrees\Statistics\Google\ChartDeath;
use Fisharebest\Webtrees\Statistics\Google\ChartFamilyWithSources;
use Fisharebest\Webtrees\Statistics\Google\ChartIndividualWithSources;
use Fisharebest\Webtrees\Statistics\Google\ChartMortality;
use Fisharebest\Webtrees\Statistics\Google\ChartSex;
use Fisharebest\Webtrees\Statistics\Helper\Sql;
use Fisharebest\Webtrees\Statistics\Repository\Interfaces\IndividualRepositoryInterface;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 *
 */
class IndividualRepository implements IndividualRepositoryInterface
{
    /**
     * @var Tree
     */
    private $tree;

    /**
     * Constructor.
     *
     * @param Tree $tree
     */
    public function __construct(Tree $tree)
    {
        $this->tree = $tree;
    }

    /**
     * Run an SQL query and cache the result.
     *
     * @param string $sql
     *
     * @return \stdClass[]
     */
    private function runSql(string $sql): array
    {
        return Sql::runSql($sql);
    }

    /**
     * Find common given names.
     *
     * @param string $sex
     * @param string $type
     * @param bool   $show_tot
     * @param int    $threshold
     * @param int    $maxtoshow
     *
     * @return string|int[]
     */
    private function commonGivenQuery(string $sex, string $type, bool $show_tot, int $threshold, int $maxtoshow)
    {
        switch ($sex) {
            case 'M':
                $sex_sql = "i_sex='M'";
                break;
            case 'F':
                $sex_sql = "i_sex='F'";
                break;
            case 'U':
                $sex_sql = "i_sex='U'";
                break;
            case 'B':
            default:
                $sex_sql = "i_sex<>'U'";
                break;
        }

        $ged_id = $this->tree->id();

        $rows     = Database::prepare("SELECT n_givn, COUNT(*) AS num FROM `##name` JOIN `##individuals` ON (n_id=i_id AND n_file=i_file) WHERE n_file={$ged_id} AND n_type<>'_MARNM' AND n_givn NOT IN ('@P.N.', '') AND LENGTH(n_givn)>1 AND {$sex_sql} GROUP BY n_id, n_givn")
            ->fetchAll();

        $nameList = [];
        foreach ($rows as $row) {
            $row->num = (int) $row->num;

            // Split “John Thomas” into “John” and “Thomas” and count against both totals
            foreach (explode(' ', $row->n_givn) as $given) {
                // Exclude initials and particles.
                if (!preg_match('/^([A-Z]|[a-z]{1,3})$/', $given)) {
                    if (\array_key_exists($given, $nameList)) {
                        $nameList[$given] += (int) $row->num;
                    } else {
                        $nameList[$given] = (int) $row->num;
                    }
                }
            }
        }
        arsort($nameList);
        $nameList = \array_slice($nameList, 0, $maxtoshow);

        foreach ($nameList as $given => $total) {
            if ($total < $threshold) {
                unset($nameList[$given]);
            }
        }

        switch ($type) {
            case 'chart':
                return $nameList;

            case 'table':
                return view('lists/given-names-table', [
                    'given_names' => $nameList,
                ]);

            case 'list':
                return view('lists/given-names-list', [
                    'given_names' => $nameList,
                    'show_totals' => $show_tot,
                ]);

            case 'nolist':
            default:
                array_walk($nameList, function (int &$value, string $key) use ($show_tot): void {
                    if ($show_tot) {
                        $value = '<span dir="auto">' . e($key);
                    } else {
                        $value = '<span dir="auto">' . e($key) . ' (' . I18N::number($value) . ')';
                    }
                });

                return implode(I18N::$list_separator, $nameList);
        }
    }

    /**
     * Find common give names.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGiven(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('B', 'nolist', false, $threshold, $maxtoshow);
    }

    /**
     * Find common give names.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenTotals(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('B', 'nolist', true, $threshold, $maxtoshow);
    }

    /**
     * Find common give names.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenList(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('B', 'list', false, $threshold, $maxtoshow);
    }

    /**
     * Find common give names.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenListTotals(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('B', 'list', true, $threshold, $maxtoshow);
    }

    /**
     * Find common give names.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenTable(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('B', 'table', false, $threshold, $maxtoshow);
    }

    /**
     * Find common give names of females.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenFemale(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('F', 'nolist', false, $threshold, $maxtoshow);
    }

    /**
     * Find common give names of females.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenFemaleTotals(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('F', 'nolist', true, $threshold, $maxtoshow);
    }

    /**
     * Find common give names of females.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenFemaleList(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('F', 'list', false, $threshold, $maxtoshow);
    }

    /**
     * Find common give names of females.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenFemaleListTotals(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('F', 'list', true, $threshold, $maxtoshow);
    }

    /**
     * Find common give names of females.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenFemaleTable(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('F', 'table', false, $threshold, $maxtoshow);
    }

    /**
     * Find common give names of males.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenMale(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('M', 'nolist', false, $threshold, $maxtoshow);
    }

    /**
     * Find common give names of males.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenMaleTotals(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('M', 'nolist', true, $threshold, $maxtoshow);
    }

    /**
     * Find common give names of males.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenMaleList(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('M', 'list', false, $threshold, $maxtoshow);
    }

    /**
     * Find common give names of males.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenMaleListTotals(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('M', 'list', true, $threshold, $maxtoshow);
    }

    /**
     * Find common give names of males.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenMaleTable(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('M', 'table', false, $threshold, $maxtoshow);
    }

    /**
     * Find common give names of unknown sexes.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenUnknown(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('U', 'nolist', false, $threshold, $maxtoshow);
    }

    /**
     * Find common give names of unknown sexes.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenUnknownTotals(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('U', 'nolist', true, $threshold, $maxtoshow);
    }

    /**
     * Find common give names of unknown sexes.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenUnknownList(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('U', 'list', false, $threshold, $maxtoshow);
    }

    /**
     * Find common give names of unknown sexes.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenUnknownListTotals(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('U', 'list', true, $threshold, $maxtoshow);
    }

    /**
     * Find common give names of unknown sexes.
     *
     * @param int $threshold
     * @param int $maxtoshow
     *
     * @return string
     */
    public function commonGivenUnknownTable(int $threshold = 1, int $maxtoshow = 10): string
    {
        return $this->commonGivenQuery('U', 'table', false, $threshold, $maxtoshow);
    }

    /**
     * Count the number of distinct given names (or the number of occurences of specific given names).
     *
     * @param string[] ...$params
     *
     * @return string
     */
    public function totalGivennames(...$params): string
    {
        $query = DB::table('name')
            ->where('n_file', '=', $this->tree->id());

        if (empty($params)) {
            // Count number of distinct given names.
            $query
                ->distinct()
                ->where('n_givn', '<>', '@P.N.')
                ->whereNotNull('n_givn');
        } else {
            // Count number of occurences of specific given names.
            $query->whereIn('n_givn', $params);
        }

        $count = $query->count('n_givn');

        return I18N::number($count);
    }

    /**
     * Count the number of distinct surnames (or the number of occurences of specific surnames).
     *
     * @param string[] ...$params
     *
     * @return string
     */
    public function totalSurnames(...$params): string
    {
        $query = DB::table('name')
            ->where('n_file', '=', $this->tree->id());

        if (empty($params)) {
            // Count number of distinct surnames
            $query->distinct()
                ->whereNotNull('n_surn');
        } else {
            // Count number of occurences of specific surnames.
            $query->whereIn('n_surn', $params);
        }

        $count = $query->count('n_surn');

        return I18N::number($count);
    }

    /**
     * @param int $number_of_surnames
     * @param int $threshold
     *
     * @return \stdClass[]
     */
    private function topSurnames(int $number_of_surnames, int $threshold): array
    {
        // Use the count of base surnames.
        $top_surnames = Database::prepare(
            "SELECT n_surn FROM `##name`" .
            " WHERE n_file = :tree_id AND n_type != '_MARNM' AND n_surn NOT IN ('@N.N.', '')" .
            " GROUP BY n_surn" .
            " ORDER BY COUNT(n_surn) DESC" .
            " LIMIT :limit"
        )->execute([
            'tree_id' => $this->tree->id(),
            'limit'   => $number_of_surnames,
        ])->fetchOneColumn();

        $surnames = [];
        foreach ($top_surnames as $top_surname) {
            $variants = Database::prepare(
                "SELECT n_surname COLLATE utf8_bin, COUNT(*) FROM `##name` WHERE n_file = :tree_id AND n_surn COLLATE :collate = :surname GROUP BY 1"
            )->execute([
                'collate' => I18N::collation(),
                'surname' => $top_surname,
                'tree_id' => $this->tree->id(),
            ])->fetchAssoc();

            if (array_sum($variants) > $threshold) {
                $surnames[$top_surname] = $variants;
            }
        }

        return $surnames;
    }

    /**
     * Find common surnames.
     *
     * @return string
     */
    public function getCommonSurname(): string
    {
        $top_surname = $this->topSurnames(1, 0);
        return implode(', ', array_keys(array_shift($top_surname)) ?? []);
    }

    /**
     * Find common surnames.
     *
     * @param string $type
     * @param bool   $show_tot
     * @param int    $threshold
     * @param int    $number_of_surnames
     * @param string $sorting
     *
     * @return string
     */
    private function commonSurnamesQuery(
        string $type,
        bool $show_tot,
        int $threshold,
        int $number_of_surnames,
        string $sorting
    ): string {
        $surnames = $this->topSurnames($number_of_surnames, $threshold);

        switch ($sorting) {
            default:
            case 'alpha':
                uksort($surnames, [I18N::class, 'strcasecmp']);
                break;
            case 'count':
                break;
            case 'rcount':
                $surnames = array_reverse($surnames, true);
                break;
        }

        return FunctionsPrintLists::surnameList(
            $surnames,
            ($type === 'list' ? 1 : 2),
            $show_tot,
            'individual-list',
            $this->tree
        );
    }

    /**
     * Find common surnames.
     *
     * @param int    $threshold
     * @param int    $number_of_surnames
     * @param string $sorting
     *
     * @return string
     */
    public function commonSurnames(
        int $threshold = 1,
        int $number_of_surnames = 10,
        string $sorting = 'alpha'
    ): string {
        return $this->commonSurnamesQuery('nolist', false, $threshold, $number_of_surnames, $sorting);
    }

    /**
     * Find common surnames.
     *
     * @param int    $threshold
     * @param int    $number_of_surnames
     * @param string $sorting
     *
     * @return string
     */
    public function commonSurnamesTotals(
        int $threshold = 1,
        int $number_of_surnames = 10,
        string $sorting = 'rcount'
    ): string {
        return $this->commonSurnamesQuery('nolist', true, $threshold, $number_of_surnames, $sorting);
    }

    /**
     * Find common surnames.
     *
     * @param int    $threshold
     * @param int    $number_of_surnames
     * @param string $sorting
     *
     * @return string
     */
    public function commonSurnamesList(
        int $threshold = 1,
        int $number_of_surnames = 10,
        string $sorting = 'alpha'
    ): string {
        return $this->commonSurnamesQuery('list', false, $threshold, $number_of_surnames, $sorting);
    }

    /**
     * Find common surnames.
     *
     * @param int    $threshold
     * @param int    $number_of_surnames
     * @param string $sorting
     *
     * @return string
     */
    public function commonSurnamesListTotals(
        int $threshold = 1,
        int $number_of_surnames = 10,
        string $sorting = 'rcount'
    ): string {
        return $this->commonSurnamesQuery('list', true, $threshold, $number_of_surnames, $sorting);
    }

    /**
     * Get a list of birth dates.
     *
     * @param bool $sex
     * @param int  $year1
     * @param int  $year2
     *
     * @return array
     */
    public function statsBirthQuery(bool $sex = false, int $year1 = -1, int $year2 = -1): array
    {
        if ($sex) {
            $sql =
                "SELECT d_month, i_sex, COUNT(*) AS total FROM `##dates` " .
                "JOIN `##individuals` ON d_file = i_file AND d_gid = i_id " .
                "WHERE " .
                "d_file={$this->tree->id()} AND " .
                "d_fact='BIRT' AND " .
                "d_type IN ('@#DGREGORIAN@', '@#DJULIAN@')";
        } else {
            $sql =
                "SELECT d_month, COUNT(*) AS total FROM `##dates` " .
                "WHERE " .
                "d_file={$this->tree->id()} AND " .
                "d_fact='BIRT' AND " .
                "d_type IN ('@#DGREGORIAN@', '@#DJULIAN@')";
        }

        if ($year1 >= 0 && $year2 >= 0) {
            $sql .= " AND d_year BETWEEN '{$year1}' AND '{$year2}'";
        }

        $sql .= " GROUP BY d_month";

        if ($sex) {
            $sql .= ", i_sex";
        }

        return $this->runSql($sql);
    }

    /**
     * General query on births.
     *
     * @param string|null $color_from
     * @param string|null $color_to
     *
     * @return string
     */
    public function statsBirth(string $color_from = null, string $color_to = null): string
    {
        return (new ChartBirth($this->tree))
            ->chartBirth($color_from, $color_to);
    }

    /**
     * Get a list of death dates.
     *
     * @param bool $sex
     * @param int  $year1
     * @param int  $year2
     *
     * @return array
     */
    public function statsDeathQuery(bool $sex = false, int $year1 = -1, int $year2 = -1): array
    {
        if ($sex) {
            $sql =
                "SELECT d_month, i_sex, COUNT(*) AS total FROM `##dates` " .
                "JOIN `##individuals` ON d_file = i_file AND d_gid = i_id " .
                "WHERE " .
                "d_file={$this->tree->id()} AND " .
                "d_fact='DEAT' AND " .
                "d_type IN ('@#DGREGORIAN@', '@#DJULIAN@')";
        } else {
            $sql =
                "SELECT d_month, COUNT(*) AS total FROM `##dates` " .
                "WHERE " .
                "d_file={$this->tree->id()} AND " .
                "d_fact='DEAT' AND " .
                "d_type IN ('@#DGREGORIAN@', '@#DJULIAN@')";
        }

        if ($year1 >= 0 && $year2 >= 0) {
            $sql .= " AND d_year BETWEEN '{$year1}' AND '{$year2}'";
        }

        $sql .= " GROUP BY d_month";

        if ($sex) {
            $sql .= ", i_sex";
        }

        return $this->runSql($sql);
    }

    /**
     * General query on deaths.
     *
     * @param string|null $color_from
     * @param string|null $color_to
     *
     * @return string
     */
    public function statsDeath(string $color_from = null, string $color_to = null): string
    {
        return (new ChartDeath($this->tree))
            ->chartDeath($color_from, $color_to);
    }

    /**
     * General query on ages.
     *
     * @param string $related
     * @param string $sex
     * @param int    $year1
     * @param int    $year2
     *
     * @return array|string
     */
    public function statsAgeQuery(string $related = 'BIRT', string $sex = 'BOTH', int $year1 = -1, int $year2 = -1)
    {
        $prefix = DB::connection()->getTablePrefix();

        $query = $this->birthAndDeathQuery($sex);

        if ($year1 >= 0 && $year2 >= 0) {
            $query
                ->whereIn('birth.d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
                ->whereIn('death.d_type', ['@#DGREGORIAN@', '@#DJULIAN@']);

            if ($related === 'BIRT') {
                $query->whereBetween('birth.d_year', [$year1, $year2]);
            } elseif ($related === 'DEAT') {
                $query->whereBetween('death.d_year', [$year1, $year2]);
            }
        }

        return $query
            ->select(DB::raw($prefix . 'death.d_julianday2 - ' . $prefix . 'birth.d_julianday1 AS days'))
            ->orderBy('days', 'desc')
            ->get()
            ->all();
    }

    /**
     * General query on ages.
     *
     * @return string
     */
    public function statsAge(): string
    {
        return (new ChartAge($this->tree))->chartAge();
    }

    /**
     * Lifespan
     *
     * @param string $type
     * @param string $sex
     *
     * @return string
     */
    private function longlifeQuery(string $type, string $sex): string
    {
        $prefix = DB::connection()->getTablePrefix();

        $row = $this->birthAndDeathQuery($sex)
            ->orderBy('days', 'desc')
            ->select(['individuals.*', DB::raw($prefix . 'death.d_julianday2 - ' . $prefix . 'birth.d_julianday1 AS days')])
            ->first();

        if ($row === null) {
            return '';
        }

        /** @var Individual $individual */
        $individual = Individual::rowMapper()($row);

        if (!$individual->canShow()) {
            return I18N::translate('This information is private and cannot be shown.');
        }

        switch ($type) {
            default:
            case 'full':
                return $individual->formatList();

            case 'age':
                return I18N::number((int) ($row->days / 365.25));

            case 'name':
                return '<a href="' . e($individual->url()) . '">' . $individual->fullName() . '</a>';
        }
    }

    /**
     * Find the longest lived individual.
     *
     * @return string
     */
    public function longestLife(): string
    {
        return $this->longlifeQuery('full', 'BOTH');
    }

    /**
     * Find the age of the longest lived individual.
     *
     * @return string
     */
    public function longestLifeAge(): string
    {
        return $this->longlifeQuery('age', 'BOTH');
    }

    /**
     * Find the name of the longest lived individual.
     *
     * @return string
     */
    public function longestLifeName(): string
    {
        return $this->longlifeQuery('name', 'BOTH');
    }

    /**
     * Find the longest lived female.
     *
     * @return string
     */
    public function longestLifeFemale(): string
    {
        return $this->longlifeQuery('full', 'F');
    }

    /**
     * Find the age of the longest lived female.
     *
     * @return string
     */
    public function longestLifeFemaleAge(): string
    {
        return $this->longlifeQuery('age', 'F');
    }

    /**
     * Find the name of the longest lived female.
     *
     * @return string
     */
    public function longestLifeFemaleName(): string
    {
        return $this->longlifeQuery('name', 'F');
    }

    /**
     * Find the longest lived male.
     *
     * @return string
     */
    public function longestLifeMale(): string
    {
        return $this->longlifeQuery('full', 'M');
    }

    /**
     * Find the age of the longest lived male.
     *
     * @return string
     */
    public function longestLifeMaleAge(): string
    {
        return $this->longlifeQuery('age', 'M');
    }

    /**
     * Find the name of the longest lived male.
     *
     * @return string
     */
    public function longestLifeMaleName(): string
    {
        return $this->longlifeQuery('name', 'M');
    }

    /**
     * Returns the calculated age the time of event.
     *
     * @param int $age The age from the database record
     *
     * @return string
     */
    private function calculateAge(int $age): string
    {
        if ((int) ($age / 365.25) > 0) {
            $result = (int) ($age / 365.25) . 'y';
        } elseif ((int) ($age / 30.4375) > 0) {
            $result = (int) ($age / 30.4375) . 'm';
        } else {
            $result = $age . 'd';
        }

        return FunctionsDate::getAgeAtEvent($result);
    }

    /**
     * Find the oldest individuals.
     *
     * @param string $sex
     * @param int    $total
     *
     * @return array
     */
    private function topTenOldestQuery(string $sex, int $total): array
    {
        $prefix = DB::connection()->getTablePrefix();

        $rows = $this->birthAndDeathQuery($sex)
            ->groupBy(['i_id', 'i_file'])
            ->orderBy('days', 'desc')
            ->select(['individuals.*', DB::raw('MAX(' . $prefix . 'death.d_julianday2 - ' . $prefix . 'birth.d_julianday1) AS days')])
            ->take($total)
            ->get();

        $top10 = [];
        foreach ($rows as $row) {
            /** @var Individual $individual */
            $individual = Individual::rowMapper()($row);

            if ($individual->canShow()) {
                $top10[] = [
                    'person' => $individual,
                    'age'    => $this->calculateAge((int) $row->days),
                ];
            }
        }

        return $top10;
    }

    /**
     * Find the oldest individuals.
     *
     * @param int $total
     *
     * @return string
     */
    public function topTenOldest(int $total = 10): string
    {
        $records = $this->topTenOldestQuery('BOTH', $total);

        return view(
            'statistics/individuals/top10-nolist',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the oldest living individuals.
     *
     * @param int $total
     *
     * @return string
     */
    public function topTenOldestList(int $total = 10): string
    {
        $records = $this->topTenOldestQuery('BOTH', $total);

        return view(
            'statistics/individuals/top10-list',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the oldest females.
     *
     * @param int $total
     *
     * @return string
     */
    public function topTenOldestFemale(int $total = 10): string
    {
        $records = $this->topTenOldestQuery('F', $total);

        return view(
            'statistics/individuals/top10-nolist',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the oldest living females.
     *
     * @param int $total
     *
     * @return string
     */
    public function topTenOldestFemaleList(int $total = 10): string
    {
        $records = $this->topTenOldestQuery('F', $total);

        return view(
            'statistics/individuals/top10-list',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the longest lived males.
     *
     * @param int $total
     *
     * @return string
     */
    public function topTenOldestMale(int $total = 10): string
    {
        $records = $this->topTenOldestQuery('M', $total);

        return view(
            'statistics/individuals/top10-nolist',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the longest lived males.
     *
     * @param int $total
     *
     * @return string
     */
    public function topTenOldestMaleList(int $total = 10): string
    {
        $records = $this->topTenOldestQuery('M', $total);

        return view(
            'statistics/individuals/top10-list',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the oldest living individuals.
     *
     * @param string $sex
     * @param int    $total
     *
     * @return array
     */
    private function topTenOldestAliveQuery(string $sex = 'BOTH', int $total = 10): array
    {
        if ($sex === 'F') {
            $sex_search = " AND i_sex='F'";
        } elseif ($sex === 'M') {
            $sex_search = " AND i_sex='M'";
        } else {
            $sex_search = '';
        }

        $rows = $this->runSql(
            "SELECT" .
            " birth.d_gid AS id," .
            " MIN(birth.d_julianday1) AS age" .
            " FROM" .
            " `##dates` AS birth," .
            " `##individuals` AS indi" .
            " WHERE" .
            " indi.i_id=birth.d_gid AND" .
            " indi.i_gedcom NOT REGEXP '\\n1 (" . implode('|', Gedcom::DEATH_EVENTS) . ")' AND" .
            " birth.d_file={$this->tree->id()} AND" .
            " birth.d_fact='BIRT' AND" .
            " birth.d_file=indi.i_file AND" .
            " birth.d_julianday1<>0" .
            $sex_search .
            " GROUP BY id" .
            " ORDER BY age" .
            " ASC LIMIT " . $total
        );

        $top10 = [];

        foreach ($rows as $row) {
            $person = Individual::getInstance($row->id, $this->tree);

            $top10[] = [
                'person' => $person,
                'age'    => $this->calculateAge(WT_CLIENT_JD - ((int) $row->age)),
            ];
        }

        return $top10;
    }

    /**
     * Find the oldest living individuals.
     *
     * @param int $total
     *
     * @return string
     */
    public function topTenOldestAlive(int $total = 10): string
    {
        if (!Auth::isMember($this->tree)) {
            return I18N::translate('This information is private and cannot be shown.');
        }

        $records = $this->topTenOldestAliveQuery('BOTH', $total);

        return view(
            'statistics/individuals/top10-nolist',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the oldest living individuals.
     *
     * @param int $total
     *
     * @return string
     */
    public function topTenOldestListAlive(int $total = 10): string
    {
        if (!Auth::isMember($this->tree)) {
            return I18N::translate('This information is private and cannot be shown.');
        }

        $records = $this->topTenOldestAliveQuery('BOTH', $total);

        return view(
            'statistics/individuals/top10-list',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the oldest living females.
     *
     * @param int $total
     *
     * @return string
     */
    public function topTenOldestFemaleAlive(int $total = 10): string
    {
        if (!Auth::isMember($this->tree)) {
            return I18N::translate('This information is private and cannot be shown.');
        }

        $records = $this->topTenOldestAliveQuery('F', $total);

        return view(
            'statistics/individuals/top10-nolist',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the oldest living females.
     *
     * @param int $total
     *
     * @return string
     */
    public function topTenOldestFemaleListAlive(int $total = 10): string
    {
        if (!Auth::isMember($this->tree)) {
            return I18N::translate('This information is private and cannot be shown.');
        }

        $records = $this->topTenOldestAliveQuery('F', $total);

        return view(
            'statistics/individuals/top10-list',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the longest lived living males.
     *
     * @param int $total
     *
     * @return string
     */
    public function topTenOldestMaleAlive(int $total = 10): string
    {
        if (!Auth::isMember($this->tree)) {
            return I18N::translate('This information is private and cannot be shown.');
        }

        $records = $this->topTenOldestAliveQuery('M', $total);

        return view(
            'statistics/individuals/top10-nolist',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the longest lived living males.
     *
     * @param int $total
     *
     * @return string
     */
    public function topTenOldestMaleListAlive(int $total = 10): string
    {
        if (!Auth::isMember($this->tree)) {
            return I18N::translate('This information is private and cannot be shown.');
        }

        $records = $this->topTenOldestAliveQuery('M', $total);

        return view(
            'statistics/individuals/top10-list',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the average lifespan.
     *
     * @param string $sex
     * @param bool   $show_years
     *
     * @return string
     */
    private function averageLifespanQuery(string $sex = 'BOTH', bool $show_years = false): string
    {
        $prefix = DB::connection()->getTablePrefix();

        $days = (int) $this->birthAndDeathQuery($sex)
            ->select(DB::raw('AVG(' . $prefix . 'death.d_julianday2 - ' . $prefix . 'birth.d_julianday1) AS days'))
            ->value('days');

        if ($show_years) {
            return $this->calculateAge($days);
        }

        return I18N::number((int) ($days / 365.25));
    }

    /**
     * Find the average lifespan.
     *
     * @param bool $show_years
     *
     * @return string
     */
    public function averageLifespan($show_years = false): string
    {
        return $this->averageLifespanQuery('BOTH', $show_years);
    }

    /**
     * Find the average lifespan of females.
     *
     * @param bool $show_years
     *
     * @return string
     */
    public function averageLifespanFemale($show_years = false): string
    {
        return $this->averageLifespanQuery('F', $show_years);
    }

    /**
     * Find the average male lifespan.
     *
     * @param bool $show_years
     *
     * @return string
     */
    public function averageLifespanMale($show_years = false): string
    {
        return $this->averageLifespanQuery('M', $show_years);
    }

    /**
     * Convert totals into percentages.
     *
     * @param int $count
     * @param int $total
     *
     * @return string
     */
    private function getPercentage(int $count, int $total): string
    {
        return I18N::percentage($count / $total, 1);
    }

    /**
     * Returns how many individuals exist in the tree.
     *
     * @return int
     */
    private function totalIndividualsQuery(): int
    {
        return DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->count();
    }

    /**
     * Count the number of living individuals.
     *
     * The totalLiving/totalDeceased queries assume that every dead person will
     * have a DEAT record. It will not include individuals who were born more
     * than MAX_ALIVE_AGE years ago, and who have no DEAT record.
     * A good reason to run the “Add missing DEAT records” batch-update!
     *
     * @return int
     */
    private function totalLivingQuery(): int
    {
        $query = DB::table('individuals')
            ->where('i_file', '=', $this->tree->id());

        foreach (Gedcom::DEATH_EVENTS as $death_event) {
            $query->where('i_gedcom', 'NOT LIKE', "%\n1 " . $death_event . '%');
        }

        return $query->count();
    }

    /**
     * Count the number of dead individuals.
     *
     * @return int
     */
    private function totalDeceasedQuery(): int
    {
        return DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->where(function (Builder $query): void {
                foreach (Gedcom::DEATH_EVENTS as $death_event) {
                    $query->orWhere('i_gedcom', 'LIKE', "%\n1 " . $death_event . '%');
                }
            })
            ->count();
    }

    /**
     * Returns the total count of a specific sex.
     *
     * @param string $sex The sex to query
     *
     * @return int
     */
    private function getTotalSexQuery(string $sex): int
    {
        return DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->where('i_sex', '=', $sex)
            ->count();
    }

    /**
     * Returns the total number of males.
     *
     * @return int
     */
    private function totalSexMalesQuery(): int
    {
        return $this->getTotalSexQuery('M');
    }

    /**
     * Returns the total number of females.
     *
     * @return int
     */
    private function totalSexFemalesQuery(): int
    {
        return $this->getTotalSexQuery('F');
    }

    /**
     * Returns the total number of individuals with unknown sex.
     *
     * @return int
     */
    private function totalSexUnknownQuery(): int
    {
        return $this->getTotalSexQuery('U');
    }

    /**
     * Count the total families.
     *
     * @return int
     */
    private function totalFamiliesQuery(): int
    {
        return DB::table('families')
            ->where('f_file', '=', $this->tree->id())
            ->count();
    }

    /**
     * How many individuals have one or more sources.
     *
     * @return int
     */
    private function totalIndisWithSourcesQuery(): int
    {
        return DB::table('individuals')
            ->select(['i_id'])
            ->distinct()
            ->join('link', function (JoinClause $join) {
                $join->on('i_id', '=', 'l_from')
                    ->on('i_file', '=', 'l_file');
            })
            ->where('l_file', '=', $this->tree->id())
            ->where('l_type', '=', 'SOUR')
            ->count('i_id');
    }

    /**
     * Count the families with source records.
     *
     * @return int
     */
    private function totalFamsWithSourcesQuery(): int
    {
        return DB::table('families')
            ->select(['f_id'])
            ->distinct()
            ->join('link', function (JoinClause $join) {
                $join->on('f_id', '=', 'l_from')
                    ->on('f_file', '=', 'l_file');
            })
            ->where('l_file', '=', $this->tree->id())
            ->where('l_type', '=', 'SOUR')
            ->count('f_id');
    }

    /**
     * Count the number of repositories.
     *
     * @return int
     */
    private function totalRepositoriesQuery(): int
    {
        return DB::table('other')
            ->where('o_file', '=', $this->tree->id())
            ->where('o_type', '=', 'REPO')
            ->count();
    }

    /**
     * Count the total number of sources.
     *
     * @return int
     */
    private function totalSourcesQuery(): int
    {
        return DB::table('sources')
            ->where('s_file', '=', $this->tree->id())
            ->count();
    }

    /**
     * Count the number of notes.
     *
     * @return int
     */
    private function totalNotesQuery(): int
    {
        return DB::table('other')
            ->where('o_file', '=', $this->tree->id())
            ->where('o_type', '=', 'NOTE')
            ->count();
    }

    /**
     * Returns the total number of records.
     *
     * @return int
     */
    private function totalRecordsQuery(): int
    {
        return $this->totalIndividualsQuery()
            + $this->totalFamiliesQuery()
            + $this->totalNotesQuery()
            + $this->totalRepositoriesQuery()
            + $this->totalSourcesQuery();
    }

    /**
     * @inheritDoc
     */
    public function totalRecords(): string
    {
        return I18N::number($this->totalRecordsQuery());
    }

    /**
     * @inheritDoc
     */
    public function totalIndividuals(): string
    {
        return I18N::number($this->totalIndividualsQuery());
    }

    /**
     * Count the number of living individuals.
     *
     * @return string
     */
    public function totalLiving(): string
    {
        return I18N::number($this->totalLivingQuery());
    }

    /**
     * Count the number of dead individuals.
     *
     * @return string
     */
    public function totalDeceased(): string
    {
        return I18N::number($this->totalDeceasedQuery());
    }

    /**
     * @inheritDoc
     */
    public function totalSexMales(): string
    {
        return I18N::number($this->totalSexMalesQuery());
    }

    /**
     * @inheritDoc
     */
    public function totalSexFemales(): string
    {
        return I18N::number($this->totalSexFemalesQuery());
    }

    /**
     * @inheritDoc
     */
    public function totalSexUnknown(): string
    {
        return I18N::number($this->totalSexUnknownQuery());
    }

    /**
     * @inheritDoc
     */
    public function totalFamilies(): string
    {
        return I18N::number($this->totalFamiliesQuery());
    }

    /**
     * How many individuals have one or more sources.
     *
     * @return string
     */
    public function totalIndisWithSources(): string
    {
        return I18N::number($this->totalIndisWithSourcesQuery());
    }

    /**
     * Count the families with with source records.
     *
     * @return string
     */
    public function totalFamsWithSources(): string
    {
        return I18N::number($this->totalFamsWithSourcesQuery());
    }

    /**
     * @inheritDoc
     */
    public function totalRepositories(): string
    {
        return I18N::number($this->totalRepositoriesQuery());
    }

    /**
     * @inheritDoc
     */
    public function totalSources(): string
    {
        return I18N::number($this->totalSourcesQuery());
    }

    /**
     * @inheritDoc
     */
    public function totalNotes(): string
    {
        return I18N::number($this->totalNotesQuery());
    }

    /**
     * @inheritDoc
     */
    public function totalIndividualsPercentage(): string
    {
        return $this->getPercentage(
            $this->totalIndividualsQuery(),
            $this->totalRecordsQuery()
        );
    }

    /**
     * @inheritDoc
     */
    public function totalFamiliesPercentage(): string
    {
        return $this->getPercentage(
            $this->totalFamiliesQuery(),
            $this->totalRecordsQuery()
        );
    }

    /**
     * @inheritDoc
     */
    public function totalRepositoriesPercentage(): string
    {
        return $this->getPercentage(
            $this->totalRepositoriesQuery(),
            $this->totalRecordsQuery()
        );
    }

    /**
     * @inheritDoc
     */
    public function totalSourcesPercentage(): string
    {
        return $this->getPercentage(
            $this->totalSourcesQuery(),
            $this->totalRecordsQuery()
        );
    }

    /**
     * @inheritDoc
     */
    public function totalNotesPercentage(): string
    {
        return $this->getPercentage(
            $this->totalNotesQuery(),
            $this->totalRecordsQuery()
        );
    }

    /**
     * @inheritDoc
     */
    public function totalLivingPercentage(): string
    {
        return $this->getPercentage(
            $this->totalLivingQuery(),
            $this->totalIndividualsQuery()
        );
    }

    /**
     * @inheritDoc
     */
    public function totalDeceasedPercentage(): string
    {
        return $this->getPercentage(
            $this->totalDeceasedQuery(),
            $this->totalIndividualsQuery()
        );
    }

    /**
     * @inheritDoc
     */
    public function totalSexMalesPercentage(): string
    {
        return $this->getPercentage(
            $this->totalSexMalesQuery(),
            $this->totalIndividualsQuery()
        );
    }

    /**
     * @inheritDoc
     */
    public function totalSexFemalesPercentage(): string
    {
        return $this->getPercentage(
            $this->totalSexFemalesQuery(),
            $this->totalIndividualsQuery()
        );
    }

    /**
     * @inheritDoc
     */
    public function totalSexUnknownPercentage(): string
    {
        return $this->getPercentage(
            $this->totalSexUnknownQuery(),
            $this->totalIndividualsQuery()
        );
    }

    /**
     * Create a chart of common given names.
     *
     * @param string|null $color_from
     * @param string|null $color_to
     * @param int         $maxtoshow
     *
     * @return string
     */
    public function chartCommonGiven(
        string $color_from = null,
        string $color_to = null,
        int $maxtoshow = 7
    ): string {
        $tot_indi = $this->totalIndividualsQuery();
        $given    = $this->commonGivenQuery('B', 'chart', false, 1, $maxtoshow);

        if (empty($given)) {
            return I18N::translate('This information is not available.');
        }

        return (new ChartCommonGiven($this->tree))
            ->chartCommonGiven($tot_indi, $given, $color_from, $color_to);
    }

    /**
     * Create a chart of common surnames.
     *
     * @param string|null $color_from
     * @param string|null $color_to
     * @param int         $number_of_surnames
     *
     * @return string
     */
    public function chartCommonSurnames(
        string $color_from = null,
        string $color_to = null,
        int $number_of_surnames = 10
    ): string {
        $tot_indi     = $this->totalIndividualsQuery();
        $all_surnames = $this->topSurnames($number_of_surnames, 0);

        if (empty($all_surnames)) {
            return I18N::translate('This information is not available.');
        }

        return (new ChartCommonSurname($this->tree))
            ->chartCommonSurnames($tot_indi, $all_surnames, $color_from, $color_to);
    }

    /**
     * Create a chart showing mortality.
     *
     * @param string|null $color_living
     * @param string|null $color_dead
     *
     * @return string
     */
    public function chartMortality(string $color_living = null, string $color_dead = null): string
    {
        $tot_l = $this->totalLivingQuery();
        $tot_d = $this->totalDeceasedQuery();

        return (new ChartMortality($this->tree))
            ->chartMortality($tot_l, $tot_d, $color_living, $color_dead);
    }

    /**
     * Create a chart showing individuals with/without sources.
     *
     * @param string|null $color_from
     * @param string|null $color_to
     *
     * @return string
     */
    public function chartIndisWithSources(
        string $color_from = null,
        string $color_to   = null
    ): string {
        $tot_indi        = $this->totalIndividualsQuery();
        $tot_indi_source = $this->totalIndisWithSourcesQuery();

        return (new ChartIndividualWithSources($this->tree))
            ->chartIndisWithSources($tot_indi, $tot_indi_source, $color_from, $color_to);
    }

    /**
     * Create a chart of individuals with/without sources.
     *
     * @param string|null $color_from
     * @param string|null $color_to
     *
     * @return string
     */
    public function chartFamsWithSources(
        string $color_from = null,
        string $color_to   = null
    ): string {
        $tot_fam        = $this->totalFamiliesQuery();
        $tot_fam_source = $this->totalFamsWithSourcesQuery();

        return (new ChartFamilyWithSources($this->tree))
            ->chartFamsWithSources($tot_fam, $tot_fam_source, $color_from, $color_to);
    }

    /**
     * @inheritDoc
     */
    public function chartSex(
        string $color_female  = null,
        string $color_male    = null,
        string $color_unknown = null
    ): string {
        $tot_m = $this->totalSexMalesQuery();
        $tot_f = $this->totalSexFemalesQuery();
        $tot_u = $this->totalSexUnknownQuery();

        return (new ChartSex($this->tree))
            ->chartSex($tot_m, $tot_f, $tot_u, $color_female, $color_male, $color_unknown);
    }

    /**
     * Query individuals, with their births and deaths.
     *
     * @param string $sex
     *
     * @return Builder
     */
    private function birthAndDeathQuery(string $sex): Builder
    {
        $query = DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->join('dates AS birth', function (JoinClause $join): void {
                $join
                    ->on('birth.d_file', '=', 'i_file')
                    ->on('birth.d_gid', '=', 'i_id');
            })
            ->join('dates AS death', function (JoinClause $join): void {
                $join
                    ->on('death.d_file', '=', 'i_file')
                    ->on('death.d_gid', '=', 'i_id');
            })
            ->where('birth.d_fact', '=', 'BIRT')
            ->where('death.d_fact', '=', 'DEAT')
            ->whereColumn('death.d_julianday1', '>=', 'birth.d_julianday2')
            ->where('birth.d_julianday2', '<>', 0);

        if ($sex === 'M' || $sex === 'F') {
            $query->where('i_sex', '=', $sex);
        }

        return $query;
    }
}