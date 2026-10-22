<?php

/**
 * Projeção de 1 a 60 meses. Recorrências têm um teto vitalício de segurança de 10.000
 * sequências, inclusive sem occurrence_limit; atingir o teto não encerra o plano automaticamente.
 */
return [
    'projection_months' => max(1, min(60, (int) env('FINANCE_PROJECTION_MONTHS', 12))),
];
