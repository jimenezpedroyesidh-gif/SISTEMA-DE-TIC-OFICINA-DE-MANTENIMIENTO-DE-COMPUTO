<?php
/** Periodo académico UFPS (formato AAAA-N: N=1 ene-jun, N=2 jul-dic). */
final class HorarioPeriodo
{
    /**
     * Periodo académico actual en formato AAAA-N (N=1 para ene-jun, N=2 para
     * jul-dic). Compartido entre horarios.php y mantenimientos_programados.php
     * para que ambos revisen choques contra el semestre que está en curso, sin
     * mezclarse con horarios de semestres pasados que ya no aplican.
     */
    public static function actual(): string {
        return date('Y') . '-' . (date('n') <= 6 ? '1' : '2');
    }
    public static function siguiente(string $periodo): string {
        [$anio, $mitad] = array_map('intval', explode('-', $periodo));
        return $mitad === 1 ? "$anio-2" : ($anio + 1) . '-1';
    }
    public static function anterior(string $periodo): string {
        [$anio, $mitad] = array_map('intval', explode('-', $periodo));
        return $mitad === 1 ? ($anio - 1) . '-2' : "$anio-1";
    }
    public static function valido(string $p): bool {
        return (bool)preg_match('/^\d{4}-[12]$/', $p);
    }
}
