<?php

// Obrok 2019 schedule — transcribed from the original harmonogram.twig
return [
    ['day' => 'Středa', 'columns' => [
        ['title' => 'Hlavní program', 'slots' => [
            ['time' => '14:00 - 20:00', 'title' => 'Registrace'],
            ['time' => '19:00 - 19:30', 'title' => 'Zahajovací ceremoniál'],
            ['time' => '19:30 - 22:00', 'title' => 'Večerní koncert'],
            ['time' => '23:00', 'title' => 'Večerka'],
        ]],
        ['title' => 'Doprovodný program', 'slots' => [
            ['time' => '17:00 - 18:30', 'title' => 'Taneční workshop #1'],
            ['time' => '20:00 - 23:00', 'title' => 'Táborák'],
            ['time' => '22:45', 'title' => 'Večerní nástup'],
        ]],
    ]],
    ['day' => 'Čtvrtek', 'columns' => [
        ['title' => 'Hlavní program', 'slots' => [
            ['time' => '7:00', 'title' => 'Budíček'],
            ['time' => '8:00', 'title' => 'Nástup'],
            ['time' => '8:00 - 13:00', 'title' => 'Služba', 'sectionId' => 1, 'lookupTime' => '08:00'],
            ['time' => '9:00 - 12:00', 'title' => 'Vzlet', 'sectionId' => 2, 'lookupTime' => '09:00'],
            ['time' => '14:00 - 18:00', 'title' => 'Služba', 'sectionId' => 1, 'lookupTime' => '15:00'],
            ['time' => '15:00 - 18:00', 'title' => 'Vzlet', 'sectionId' => 2, 'lookupTime' => '15:00'],
            ['time' => '19:30', 'title' => 'Večerní ceremoniál'],
            ['time' => '20:00 - 23:00', 'title' => 'Swing, bejby!'],
            ['time' => '20:00 - 21:00', 'title' => 'Televize Seznam - V centru'],
            ['time' => '21:00 - 22:00', 'title' => 'Improshow'],
            ['time' => '23:00', 'title' => 'Večeře'],
        ]],
        ['title' => 'Doprovodný program', 'slots' => [
            ['time' => '7:05', 'title' => 'Rozcvička'],
            ['time' => '12:00 - 13:30', 'title' => 'Taneční workshop #2'],
            ['time' => '13:30 - 15:00', 'title' => 'Taneční workshop #3'],
            ['time' => '20:00 - 23:00', 'title' => 'Táborák'],
            ['time' => '20:00 - 22:00', 'title' => 'Filmový večer'],
            ['time' => '22:00 - 22:45', 'title' => 'Večerní zamyšlení'],
            ['time' => '22:45', 'title' => 'Večerní nástup'],
        ]],
    ]],
    ['day' => 'Pátek', 'columns' => [
        ['title' => 'Hlavní program', 'slots' => [
            ['time' => '7:30', 'title' => 'Budíček'],
            ['time' => '8:00', 'title' => 'Nástup'],
            ['time' => '9:00 - 10:30', 'title' => 'M(a)y Day'],
            ['time' => '9:00 - 10:30', 'title' => 'Pamětníci', 'sectionId' => 11, 'lookupTime' => '09:00'],
            ['time' => '11:00 - 12:30', 'title' => 'Pamětníci', 'sectionId' => 11, 'lookupTime' => '11:00'],
            ['time' => '11:00 - 12:30', 'title' => 'M(a)y Day'],
            ['time' => '14:30 - 17:30', 'title' => 'Velká hra'],
            ['time' => '19:30', 'title' => 'Večerní ceremoniál'],
            ['time' => '22:00 - 23:00', 'title' => 'Večer skautských kapel'],
            ['time' => '23:00', 'title' => 'Večerka'],
        ]],
        ['title' => 'Doprovodný program', 'slots' => [
            ['time' => '7:35', 'title' => 'Rozcvička'],
            ['time' => '9:30 - 13:00', 'title' => 'Zpátky do dětství'],
            ['time' => '18:00 - 22:00', 'title' => 'Evropské volby'],
            ['time' => '20:00 - 23:00', 'title' => 'Táborák'],
            ['time' => '20:00 - 22:00', 'title' => 'Filmový večer'],
            ['time' => '22:00 - 22:45', 'title' => 'Večerní zamyšlení'],
            ['time' => '22:45', 'title' => 'Večerní nástup'],
        ]],
    ]],
    ['day' => 'Sobota', 'columns' => [
        ['title' => 'Hlavní program', 'slots' => [
            ['time' => '7:30', 'title' => 'Budíček'],
            ['time' => '8:00', 'title' => 'Nástup'],
            ['time' => '8:15 - 9:00', 'title' => 'Sobotní snídaně'],
            ['time' => '9:00 - 12:00', 'title' => 'EXPO'],
            ['time' => '9:00 - 12:00', 'title' => 'Netradiční sporty'],
            ['time' => '14:00 - 15:30', 'title' => 'VaPro - přednáška #1', 'sectionId' => 3, 'lookupTime' => '14:00'],
            ['time' => '16:00 - 17:30', 'title' => 'VaPro - přednáška #2', 'sectionId' => 4, 'lookupTime' => '16:00'],
            ['time' => '19:00 - 20:00', 'title' => 'Závěrečný ceremoniál'],
            ['time' => '20:00 - 00:00', 'title' => 'Večerní koncerty'],
            ['time' => '00:00', 'title' => 'Večerka'],
        ]],
        ['title' => 'Doprovodný program', 'slots' => [
            ['time' => '7:35', 'title' => 'Rozcvička'],
            ['time' => '8:00 - 14:00', 'title' => 'Evropské volby'],
            ['time' => '9:00 - 12:00', 'title' => 'Workshopy'],
            ['time' => '14:00 - 16:00', 'title' => 'Workshopy'],
            ['time' => '14:00 - 18:00', 'title' => 'Netradiční sporty'],
            ['time' => '17:45 - 18:30', 'title' => 'Večerní zamyšlení'],
            ['time' => '20:00 - 0:00', 'title' => 'Táborák'],
            ['time' => '20:00 - 22:00', 'title' => 'Filmový večer'],
            ['time' => '23:45', 'title' => 'Večerní nástup'],
        ]],
    ]],
    ['day' => 'Neděle', 'columns' => [
        ['title' => 'Hlavní program', 'slots' => [
            ['time' => '7:30', 'title' => 'Budíček'],
            ['time' => '8:00', 'title' => 'Nástup'],
            ['time' => '9:00 - 9:30', 'title' => 'Beseda s knězem'],
            ['time' => '9:30 - 10:30', 'title' => 'Mše'],
            ['time' => '10:30', 'title' => 'Konec akce'],
        ]],
        ['title' => 'Doprovodný program', 'slots' => [
            ['time' => '7:35', 'title' => 'Rozcvička'],
        ]],
    ]],
];
