<?php
/**
 * Un cambio de pieza que no se puede aprobar: quien aprueba no es técnico, o la propuesta ya no
 * está pendiente (ya se aplicó, o las piezas se movieron). El mensaje se puede mostrar al usuario.
 */
final class CambioPiezaNoAplicable extends RuntimeException
{
}
