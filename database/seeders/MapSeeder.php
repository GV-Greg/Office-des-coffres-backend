<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Kingdom;
use App\Models\Province;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MapSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $kingdoms = [
            'Kingdom of France',
            'Holy Roman German Empire',
            "Kingdom of England",
            "Kingdom of Scotland",
            "Corona de Aragón",
            'Corona de Castilla y León',
            'Regno delle Due Sicilie',
            'Reino de Portugal',
            'Ireland',
            'Serenissima Repubblica di Venezia',
            'Kalmar Union',
        ];

        foreach ($kingdoms as $kingdom) {
            Kingdom::create(['kingdom_name' => $kingdom]);
        }

        $provinces = [
            ["Comté d'Artois", 'Duché de Champagne', 'Duché de Normandie', 'Duché de Bretagne',
                'Duché de Bourgogne', 'Duché du Berry', 'Duché du Bourbonnais-Auvergne', 'Duché de Touraine',
                "Duché d'Anjou", 'Comté du Poitou', 'Comté du Limousin et de La Marche', 'Duché du Lyonnais-Dauphiné',
                'Comté de Flandres', 'Comté du Languedoc', 'Comté du Périgord', "Duché d'Orléans", 'Comté du Maine',
                "Duché d'Alençon", 'Duché de Guyenne', 'Duché de Gascogne', 'Comté de Béarn',
                "Comté d'Armagnac et de Comminges", 'Comté de Toulouse', 'Comté de Rouergue', 'Ville franche'],
            ['Franche-Comté', 'Duché de Savoie', 'Comté de Provence', 'Markgrafschaft von Baden',
                'Grafschaft von Württemberg', 'Duché de Lorraine', 'Grafschaft von Augsburg',
                'Schweizerische Eidgenossenschaft', 'Ducato di Milano', 'Graafschap Holland',
                'Herzogtum von Bayern', 'Erzherzogtum von Österreich', 'Ducato di Modena',
                'Repubblica di Genova',  'Burggrafschaft von Nürnberg', 'Fürstentum Mainz',
                'Repubblica fiorentina', 'Herzogtum Steiermark', 'Repubblica di Siena', 'Kraljestvo Celje',
                'Ville franche'],
            ['Sussex', 'Devon', 'Mercia', 'Westmorland', 'Ville franche'],
            ['County of Galloway', 'County of Glasgow', 'Ville franche'],
            ['Reino de Aragón', 'Principado de Cataluña', 'Reino de Valencia', 'Ville franche'],
            ['Reino de Castilla', 'Ville franche'],
            ['Provincia di Terra di Lavoro', 'Provincia degli Abruzzi', 'Ville franche'],
            ['Condado do Porto', 'Condado de Coimbra', 'Condado de Lisboa', 'Ville franche'],
            ['An Mumhain', 'Laighean', 'Cúige Chonnacht', 'Ville franche'],
            ['Serenissima Repubblica di Venezia', 'Ville franche'],
            ['Kongeriget Danmark', 'Suomen herttuakunta', 'Kungadömet Sverige', 'Ville franche'],
        ];

        $cities = [
            // Royaume de France
            ['Arras', 'Bertincourt', 'Cambrai', 'Péronne', 'Azincourt', 'Calais'],
            ['Reims', 'Sainte-Menehould', 'Clermont', 'Varennes', 'Argonne', 'Compiègne', 'Troyes', 'Conflans-lès-Sens', 'Langres'],
            ['Rouen', 'Honfleur', 'Dieppe', 'Bayeux', 'Fécamp', 'Lisieux', 'Avranches'],
            ['Rennes', 'Rohan', 'Saint-Brieuc', 'Fougères', 'Vannes', 'Tréguier', 'Rieux', 'Saint Pol de Léon', 'Brest'],
            ['Dijon', 'Tonnerre', 'Joinville', 'Cosne', 'Sémur', 'Nevers', 'Autun', 'Mâcon', 'Chalon'    ],
            ['Bourges', 'Sancerre', 'Châteauroux', 'Saint-Aignan'],
            ['Clermont', 'Bourbon', 'Moulins', 'Montpensier', 'Thiers', 'Montluçon', 'Montbrisson', 'Murat', 'Aurillac', 'Polignac'],
            ['Tours', 'Loches', 'Chinon', 'Vendôme'],
            ['Angers', 'Saumur', 'La Flêche', 'Craon'],
            ['Poitiers', 'Niort', 'La Rochelle', 'Thouars', 'La Trémouille', 'Saintes'],
            ['Limoges', 'Tulle', 'Ventadour', 'Bourganeuf', 'Guéret', 'Rochechouart'],
            ['Lyon', 'Vienne', 'Valence', 'Montélimar', 'Dié', 'Embrun', 'Briançon'],
            ['Bruges', 'Dunkerque', 'Tournai', 'Gent', 'Antwerpen'],
            ['Montpellier', 'Nîmes', 'Lodève', 'Béziers', 'Narbonne', 'Carcassonne', 'Alais', 'Mende', 'Uzès'],
            ['Périgueux', 'Angoulême', 'Sarlat', 'Bergerac', 'Castillon'],
            ['Orléans', 'Blois', 'Patay', 'Montargis', 'Gien'],
            ['Le Mans', 'Montmirail', 'Mayenne', 'Laval'],
            ['Alençon', 'Mortagne', 'Verneuil', 'Argentan'],
            ['Bordeaux', 'La Teste-de-Buch', 'Bazas', 'Marmande', 'Agen', 'Montauban', 'Cahors', 'Blaye'],
            ['Mont-de-Marsan', 'Mimizan', 'Labrit', 'Dax', 'Bayonne'],
            ['Pau', 'Orthez', 'Mauléon', 'Tarbes', 'Lourdes'],
            ['Auch', 'Eauze', 'Lectoure', 'Muret', 'Saint-Liziers', 'Saint Bertrand de Comminges'],
            ['Toulouse', 'Foix', 'Castelnaudary', 'Castres', 'Albi'],
            ['Rodez', 'Villefranche-de-Rouergue', 'Espalion', 'Millau' ],
            // Empire
            ['Dole', 'Poligny', 'Saint Claude', 'Vesoul', 'Luxeuil', 'Pontarlier'],
            ['Chambéry', 'Bourg', 'Belley', 'Annecy'],
            ['Aix', 'Marseille', 'Brignoles', 'Arles'],
            ['Baden', 'Lörrach', 'Freiburg', 'Offenburg'],
            ['Stuttgart', 'Heilbronn', 'Rottweil', 'Zollern', 'Reutlingen', 'Esslingen', 'Zwiefalten', 'Ulm'],
            ['Nancy', 'Epinal', 'Vaudemont', 'Toul'],
            ['Augsburg', 'Lindau', 'Schaffhausen', 'Konstanz', 'Ravensburg', 'Memmingen'],
            ['Fribourg', 'Genève', 'Grandson', 'Sion', 'Solothurn', 'Schwyz', 'Luzern'],
            ['Milano', 'Pavia', 'Lodi', 'Novara', 'Como', 'Alessandria', 'Piacenza', 'Parma', 'Fornovo'],
            ['Amsterdam', 'Rotterdam', 'Leiden', 'Utrecht', 'Heusden', 'Haarlem'],
            ['München', 'Ingolstadt', 'Regensburg', 'Deggendorf', 'Passau', 'Landshut', 'Freising'],
            ['Wien', 'Mistelbach', 'Linz', 'Amstetten', 'Ternitz'],
            ['Modena', 'Guastalla', 'Mantua', 'Mirandola', 'Massa'],
            ['Genova', 'La Spezia', 'Chiavari', 'Savona', 'Ventimiglia', 'Albenga'],
            ['Nürnberg', 'Schwäbisch Hall', 'Rothenburg', 'Ansbach', 'Eichstätt'],
            ['Mainz', 'Buchen', 'Aschaffenburg', 'Frankfurt', 'Bad Mergentheim'],
            ['Firenze', 'Pisa', 'Livorno', 'Volterra', 'San Miniato', 'Pistoia', 'Piombino', 'Montevarchi', 'Arezzo', 'Montepulciano'],
            ['Graz', 'Marburg an der Drau', 'Bruck an der Mur', 'Rottenmann'],
            ['Siena', 'Grosseto', 'Orbetello', 'Santa Fiora'],
            ['Celje'],
            // Angleterre
            ['Hastings', 'Lewes', 'Arundel', 'Dover'],
            ['Bristol', 'Salisbury', 'Southampton', 'Chard', 'Dartmouth', 'Bridgewater', 'Barnstaple'],
            ['Worcester', 'Evesham', 'Lichfield', 'Derby'],
            ['Kendal', 'Holywell', 'Manchester', 'Liverpool', 'Penrith', 'Egremont'],
            // Écosse
            ['Wigtown', 'Kirkcudbright', 'Whithorn', 'Girvan'],
            ['Glasgow', 'Stirling', 'Ardencaple'],
            // Aragon
            ['Zaragoza', 'Fraga', 'Monzón', 'Huesca', 'Jaca', 'Caspe', 'Calatayud'],
            ['Barcelona', 'Lérida', 'Urgel', 'Gerona', 'Tarragona', 'Tortosa', 'Vic', 'Puigcerdà'],
            ['Valencia', 'Castellón', 'Segorbe', 'Játiva', 'Dénia'],
            // Castille et Léon
            ['Burgos', 'Osma', 'Soria', 'Aranda de Duero', 'Valladolid'],
            // Deux-Siciles
            ['Capua', 'Gaeta', 'Pontecorvo', 'Sora', 'Sessa Aurunca', 'Terracina'],
            ["L'Aquila", 'Avezzano', 'Silvi', 'Chieti', 'Sulmona', 'Tagliacozzo', 'Teramo'],
            // Portugal
            ['Porto', 'Chaves', 'Braga', 'Lamego'],
            ['Coimbra', 'Viseu', 'Aveiro', 'Guarda', 'Leiria', 'Alcobaça'],
            ['Lisboa', 'Santarém', 'Setúbal', 'Alcácer do Sal', 'Montemor', 'Avis', 'Crato', 'Évora', 'Elvas'],
            // Irlande
            ['Corcaigh', 'Imleach', 'Lios Mór'],
            ['Cill Chainnigh', 'An Caiseal', 'Ceatharlach', 'An tInbhear Mór', 'Port Láirge'],
            ['An Gort', 'Baile Locha Riach', 'Baile Átha Luain'],
            // Venise
            ['Venezia', 'Verona', 'Padova', 'Treviso', 'Portogruaro', 'Udine', 'Parenzo', 'Pola'],
            // Union de Kalmar (Danemark, Finlande, Suède)
            ['Helsingør'],
            ['Rauma', 'Turku'],
            ['Gefle', 'Falun'],
        ];

        $count_provinces = 0;
        $line_cities = 0;
        for ($i = 0; $i < count($kingdoms); $i++) {
            foreach ($provinces[$i] as $province) {
                Province::create(['kingdom_id' => $i + 1, 'province_name' => $province]);
                if ($province != 'Ville franche') {
                    $count_cities = 1;
                    foreach ($cities[$line_cities] as $city) {
                        $capital = $count_cities == 1 ? 1 : false;
                        City::create(['province_id' => $count_provinces + 1, 'city_name' => $city, 'is_capital' => $capital]);
                        $count_cities++;
                    }
                    $line_cities++;
                }
                $count_provinces++;
            }
        }
    }
}
