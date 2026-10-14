<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Comment;
use App\Models\Deal;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

class DemoDealsSeeder extends Seeder
{
    /**
     * Seed repeatable marketplace content without touching robot-related data.
     */
    public function run(): void
    {
        $categories = $this->categories();
        $counts = [
            'users' => ['created' => 0, 'updated' => 0],
            'deals' => ['created' => 0, 'updated' => 0],
            'votes' => ['created' => 0, 'updated' => 0],
            'comments' => ['created' => 0, 'updated' => 0],
        ];
        $demoUsers = $this->users($counts);
        $now = now();

        foreach ($this->offers() as $index => $offer) {
            $price = (float) $offer['price'];
            $originalPrice = (float) $offer['original_price'];
            $discount = 1 - ($price / $originalPrice);
            if ($originalPrice <= $price || $discount < 0.10 || $discount > 0.60) {
                throw new LogicException("La oferta de demostración {$offer['title']} tiene un descuento inválido.");
            }

            $author = $demoUsers[$index % count($demoUsers)];
            $createdAt = $now->copy()->subDays(29 - ($index % 30))->subMinutes($index);
            $url = $this->storeUrl($offer['store']).'?demo='.($index + 1);
            $existingDeal = Deal::query()->where('url', $url)->first();
            $deal = Deal::updateOrCreate(
                ['url' => $url],
                [
                    'user_id' => $author->id,
                    'title' => $offer['title'],
                    'description' => $offer['description'],
                    'image' => 'https://picsum.photos/seed/'.Str::slug($offer['title']).'/600/600',
                    'price' => $offer['price'],
                    'currency' => 'MXN',
                    'original_price' => $offer['original_price'],
                    'store' => $offer['store'],
                    'category_id' => $categories[$offer['category']],
                    'expires_at' => $index % 2 === 0 ? $now->copy()->addDays(($index % 14) + 1) : null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ],
            );
            $deal->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
            $counts['deals'][$existingDeal ? 'updated' : 'created']++;

            foreach ($this->votesFor($index) as $voteIndex => $value) {
                $voter = $demoUsers[($index + $voteIndex + 1) % count($demoUsers)];
                $existingVote = Vote::query()
                    ->where('user_id', $voter->id)
                    ->where('deal_id', $deal->id)
                    ->exists();
                Vote::updateOrCreate(
                    ['user_id' => $voter->id, 'deal_id' => $deal->id],
                    ['value' => $value],
                );
                $counts['votes'][$existingVote ? 'updated' : 'created']++;
            }

            foreach ($this->commentsFor($index) as $commentIndex => $body) {
                $commenter = $demoUsers[($index + $commentIndex + 1) % count($demoUsers)];
                $commentCreatedAt = $createdAt->copy()->addHours($commentIndex + 1)->addMinutes($index);
                $commentIdentity = [
                    'user_id' => $commenter->id,
                    'deal_id' => $deal->id,
                    'body' => $body,
                ];
                $existingComment = Comment::query()->where($commentIdentity)->exists();
                $comment = Comment::updateOrCreate(
                    $commentIdentity,
                    [
                        'created_at' => $commentCreatedAt,
                        'updated_at' => $commentCreatedAt,
                    ],
                );
                $comment->forceFill([
                    'created_at' => $commentCreatedAt,
                    'updated_at' => $commentCreatedAt,
                ])->save();
                $counts['comments'][$existingComment ? 'updated' : 'created']++;
            }
        }

        foreach ($counts as $table => $actions) {
            $this->command?->info(sprintf(
                '%s: %d creados, %d actualizados',
                ucfirst($table),
                $actions['created'],
                $actions['updated'],
            ));
        }
        $this->command?->info('robots: 0 tocados');
    }

    /**
     * @return array<string, int>
     */
    private function categories(): array
    {
        $names = [
            'tecnologia' => 'Tecnología',
            'hogar' => 'Hogar',
            'moda' => 'Moda',
            'supermercado' => 'Supermercado',
            'videojuegos' => 'Videojuegos',
            'viajes' => 'Viajes',
            'salud-y-belleza' => 'Salud y belleza',
        ];
        $categories = [];

        foreach ($names as $slug => $name) {
            $categories[$slug] = Category::firstOrCreate(['slug' => $slug], ['name' => $name])->id;
        }

        return $categories;
    }

    /**
     * @return list<User>
     */
    private function users(array &$counts): array
    {
        if (! User::query()->whereKey(1)->exists()) {
            throw new LogicException('Se requieren los datos existentes del usuario id 1 para protegerlos durante la siembra demo.');
        }

        $names = ['Mariana López', 'Jorge Hernández', 'Fernanda Ramírez', 'Luis Alberto Cruz', 'Sofía Mendoza', 'Diego Navarro'];
        $password = env('DEMO_USER_PASSWORD') ?: Str::random(40);
        $users = [];

        foreach ($names as $index => $name) {
            $email = 'demo'.($index + 1).'@ofertasmx.test';
            $existingUser = User::query()->where('email', $email)->first();
            if ($existingUser?->id === 1) {
                throw new LogicException("El usuario id 1 no puede ser una cuenta demo ({$email}).");
            }

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => $existingUser?->password ?? Hash::make($password),
                ],
            );
            $counts['users'][$existingUser ? 'updated' : 'created']++;
            $users[] = $user;
        }

        return $users;
    }

    /**
     * @return list<array{category: string, title: string, description: string, price: string, original_price: string, store: string}>
     */
    private function offers(): array
    {
        return [
            ['category' => 'tecnologia', 'title' => 'Smart TV 50 pulgadas 4K', 'description' => "Panel 4K con aplicaciones de streaming y control por voz.\nPrecio con descuento directo; envío gratis en compras participantes.", 'price' => '4999.00', 'original_price' => '7499.00', 'store' => 'Walmart'],
            ['category' => 'tecnologia', 'title' => 'Audífonos inalámbricos con cancelación de ruido', 'description' => "Cancelación activa y hasta 30 horas de batería.\nEl precio aparece al iniciar sesión; envío gratis desde $599.", 'price' => '1299.00', 'original_price' => '1999.00', 'store' => 'Amazon'],
            ['category' => 'tecnologia', 'title' => 'Tableta de 10 pulgadas con 128 GB', 'description' => "Pantalla Full HD, 128 GB de almacenamiento y funda incluida.\nDescuento aplicado al agregarla al carrito.", 'price' => '2899.00', 'original_price' => '3999.00', 'store' => 'Liverpool'],
            ['category' => 'tecnologia', 'title' => 'Monitor de 27 pulgadas QHD', 'description' => "Resolución QHD y soporte ajustable para trabajar o estudiar.\nEnvío gratis a partir de $599.", 'price' => '3499.00', 'original_price' => '4999.00', 'store' => 'Mercado Libre'],
            ['category' => 'tecnologia', 'title' => 'Bocina portátil resistente al agua', 'description' => "Batería de larga duración y conexión Bluetooth.\nPrecio especial por tiempo limitado.", 'price' => '799.00', 'original_price' => '1199.00', 'store' => 'Coppel'],
            ['category' => 'tecnologia', 'title' => 'Disco de estado sólido externo de 1 TB', 'description' => "Almacenamiento portátil con conexión USB-C.\nDisponible con descuento directo y entrega a domicilio.", 'price' => '1399.00', 'original_price' => '2199.00', 'store' => 'Costco'],
            ['category' => 'tecnologia', 'title' => 'Router Wi-Fi de doble banda', 'description' => "Cobertura para varias habitaciones y configuración sencilla.\nPrecio rebajado; consulta disponibilidad en tu zona.", 'price' => '899.00', 'original_price' => '1299.00', 'store' => 'Sears'],

            ['category' => 'hogar', 'title' => 'Freidora de aire 5 L', 'description' => "Capacidad familiar con temperatura ajustable y canastilla removible.\nEnvío gratis en compras mayores a $599.", 'price' => '899.00', 'original_price' => '1599.00', 'store' => 'Bodega Aurrerá'],
            ['category' => 'hogar', 'title' => 'Juego de sábanas matrimonial', 'description' => "Incluye sábana plana, sábana de cajón y dos fundas.\nDescuento aplicado al seleccionar el color participante.", 'price' => '449.00', 'original_price' => '699.00', 'store' => 'Walmart'],
            ['category' => 'hogar', 'title' => 'Licuadora de vaso de vidrio', 'description' => "Vaso resistente y varias velocidades para preparar bebidas.\nPrecio especial por tiempo limitado.", 'price' => '599.00', 'original_price' => '999.00', 'store' => 'Coppel'],
            ['category' => 'hogar', 'title' => 'Silla ergonómica para escritorio', 'description' => "Respaldo ajustable y asiento acolchado para jornadas largas.\nEnvío gratis en artículos seleccionados.", 'price' => '1899.00', 'original_price' => '2999.00', 'store' => 'Liverpool'],
            ['category' => 'hogar', 'title' => 'Juego de 6 contenedores herméticos', 'description' => "Contenedores apilables para organizar la despensa.\nEl precio incluye descuento directo en línea.", 'price' => '249.00', 'original_price' => '399.00', 'store' => 'Amazon'],
            ['category' => 'hogar', 'title' => 'Colchón matrimonial de espuma', 'description' => "Firmeza media y entrega a domicilio en zonas participantes.\nVálido con tarjeta participante.", 'price' => '3999.00', 'original_price' => '5999.00', 'store' => 'Sears'],
            ['category' => 'hogar', 'title' => 'Juego de sartenes antiadherentes de 3 piezas', 'description' => "Tres tamaños para cocinar a diario y mangos de fácil agarre.\nConsulta existencias en tu sucursal.", 'price' => '699.00', 'original_price' => '1099.00', 'store' => 'Costco'],

            ['category' => 'moda', 'title' => 'Tenis running para hombre', 'description' => "Suela ligera y malla transpirable para correr o caminar.\nDescuento disponible en tallas seleccionadas.", 'price' => '899.00', 'original_price' => '1499.00', 'store' => 'Coppel'],
            ['category' => 'moda', 'title' => 'Mochila urbana con compartimento para laptop', 'description' => "Compartimento acolchado y varios bolsillos organizadores.\nEnvío gratis desde $599.", 'price' => '499.00', 'original_price' => '799.00', 'store' => 'Amazon'],
            ['category' => 'moda', 'title' => 'Chamarra ligera para mujer', 'description' => "Corte casual para temporada fresca y cierre frontal.\nPrecio rebajado en colores participantes.", 'price' => '699.00', 'original_price' => '1199.00', 'store' => 'Liverpool'],
            ['category' => 'moda', 'title' => 'Reloj casual con correa metálica', 'description' => "Diseño para uso diario con carátula fácil de leer.\nLa promoción se refleja al finalizar la compra.", 'price' => '799.00', 'original_price' => '1299.00', 'store' => 'Sears'],
            ['category' => 'moda', 'title' => 'Sudadera con capucha unisex', 'description' => "Tela afelpada y corte cómodo para uso diario.\nOferta válida en tallas y colores seleccionados.", 'price' => '399.00', 'original_price' => '649.00', 'store' => 'Walmart'],
            ['category' => 'moda', 'title' => 'Maleta de cabina rígida', 'description' => "Tamaño para equipaje de mano con ruedas giratorias.\nEnvío gratis al comprar en línea.", 'price' => '1199.00', 'original_price' => '1999.00', 'store' => 'Mercado Libre'],
            ['category' => 'moda', 'title' => 'Sandalias cómodas para mujer', 'description' => "Plantilla acolchada para caminar y diseño fácil de combinar.\nDescuento en modelos seleccionados.", 'price' => '349.00', 'original_price' => '549.00', 'store' => 'Bodega Aurrerá'],

            ['category' => 'supermercado', 'title' => 'Pack de 12 rollos de papel higiénico', 'description' => "Paquete familiar para surtir la despensa.\nPrecio especial con compra en línea; sujeto a disponibilidad.", 'price' => '119.00', 'original_price' => '169.00', 'store' => 'Bodega Aurrerá'],
            ['category' => 'supermercado', 'title' => 'Cápsulas de café, paquete de 30', 'description' => "Selección de sabores para preparar en casa.\nEnvío gratis a partir de $599.", 'price' => '189.00', 'original_price' => '279.00', 'store' => 'Costco'],
            ['category' => 'supermercado', 'title' => 'Aceite de oliva extra virgen 1 L', 'description' => "Botella de un litro para cocinar y aderezar.\nDescuento directo mientras haya existencias.", 'price' => '199.00', 'original_price' => '299.00', 'store' => 'Walmart'],
            ['category' => 'supermercado', 'title' => 'Detergente líquido para ropa 5 L', 'description' => "Presentación rendidora para varias cargas de lavado.\nPrecio participante al comprar en línea.", 'price' => '179.00', 'original_price' => '269.00', 'store' => 'Bodega Aurrerá'],
            ['category' => 'supermercado', 'title' => 'Paquete de 24 bebidas de agua mineral', 'description' => "Latas individuales para tener bebidas frías en casa.\nConsulta cobertura y costo de envío.", 'price' => '229.00', 'original_price' => '349.00', 'store' => 'Costco'],
            ['category' => 'supermercado', 'title' => 'Cereal integral en paquete de 2', 'description' => "Dos cajas para el desayuno de toda la semana.\nLa oferta aplica en presentación seleccionada.", 'price' => '99.00', 'original_price' => '149.00', 'store' => 'Walmart'],
            ['category' => 'supermercado', 'title' => 'Café molido de tueste medio 1 kg', 'description' => "Bolsa de un kilo para preparar café en casa.\nPrecio reducido por tiempo limitado.", 'price' => '219.00', 'original_price' => '329.00', 'store' => 'Amazon'],

            ['category' => 'videojuegos', 'title' => 'Control inalámbrico para consola', 'description' => "Control recargable con vibración y conexión inalámbrica.\nDescuento aplicado al seleccionar la versión participante.", 'price' => '999.00', 'original_price' => '1599.00', 'store' => 'Amazon'],
            ['category' => 'videojuegos', 'title' => 'Juego de aventura para consola', 'description' => "Explora un mundo abierto en esta aventura para un jugador.\nPrecio especial en formato físico.", 'price' => '799.00', 'original_price' => '1299.00', 'store' => 'Liverpool'],
            ['category' => 'videojuegos', 'title' => 'Teclado mecánico compacto', 'description' => "Formato compacto con iluminación configurable.\nEnvío gratis a partir de $599.", 'price' => '699.00', 'original_price' => '1099.00', 'store' => 'Mercado Libre'],
            ['category' => 'videojuegos', 'title' => 'Audífonos para jugar con micrófono', 'description' => "Micrófono integrado y almohadillas cómodas para sesiones largas.\nOferta en línea por tiempo limitado.", 'price' => '549.00', 'original_price' => '899.00', 'store' => 'Coppel'],
            ['category' => 'videojuegos', 'title' => 'Crédito digital para juegos de PC', 'description' => "Saldo digital para elegir títulos en la tienda de juegos.\nSe entrega como código al completar el pago.", 'price' => '399.00', 'original_price' => '599.00', 'store' => 'Steam'],
            ['category' => 'videojuegos', 'title' => 'Silla gamer reclinable', 'description' => "Respaldo reclinable y cojines para soporte lumbar.\nVálido con tarjeta participante y envío a domicilio.", 'price' => '2999.00', 'original_price' => '4499.00', 'store' => 'Sears'],
            ['category' => 'videojuegos', 'title' => 'Suscripción de videojuegos por 3 meses', 'description' => "Acceso temporal a un catálogo de juegos seleccionados.\nCanje digital; revisa los requisitos de la plataforma.", 'price' => '499.00', 'original_price' => '799.00', 'store' => 'Amazon'],

            ['category' => 'viajes', 'title' => 'Vuelo redondo CDMX-Cancún', 'description' => "Tarifa para viajar en fechas seleccionadas durante los próximos meses.\nEl precio puede variar según equipaje y disponibilidad.", 'price' => '1899.00', 'original_price' => '3299.00', 'store' => 'Mercado Libre'],
            ['category' => 'viajes', 'title' => 'Noche de hotel para dos en Guadalajara', 'description' => "Hospedaje para dos personas con desayuno incluido.\nReserva anticipada y fechas sujetas a disponibilidad.", 'price' => '1499.00', 'original_price' => '2499.00', 'store' => 'Liverpool'],
            ['category' => 'viajes', 'title' => 'Autobús redondo Querétaro-Ciudad de México', 'description' => "Viaje redondo en servicio ejecutivo para fechas seleccionadas.\nCompra anticipada; cambios sujetos a condiciones de la tarifa.", 'price' => '399.00', 'original_price' => '649.00', 'store' => 'Amazon'],
            ['category' => 'viajes', 'title' => 'Paquete de equipaje para viaje corto', 'description' => "Incluye maleta de cabina y organizadores de viaje.\nDescuento directo en productos seleccionados.", 'price' => '899.00', 'original_price' => '1499.00', 'store' => 'Coppel'],
            ['category' => 'viajes', 'title' => 'Entrada para función de cine en pareja', 'description' => "Dos entradas para funciones participantes entre semana.\nCompra en línea; horarios y salas sujetos a disponibilidad.", 'price' => '149.00', 'original_price' => '249.00', 'store' => 'Cinemex'],
            ['category' => 'viajes', 'title' => 'Renta de auto por un día en Monterrey', 'description' => "Tarifa de renta para fechas seleccionadas y kilometraje limitado.\nRequiere tarjeta de crédito y puede incluir cargos adicionales.", 'price' => '699.00', 'original_price' => '1199.00', 'store' => 'Walmart'],

            ['category' => 'salud-y-belleza', 'title' => 'Crema hidratante facial 200 ml', 'description' => "Hidratación diaria para rostro y cuello.\nPrecio con descuento directo; envío gratis desde $599.", 'price' => '249.00', 'original_price' => '399.00', 'store' => 'Amazon'],
            ['category' => 'salud-y-belleza', 'title' => 'Protector solar facial FPS 50', 'description' => "Protección para uso diario con textura ligera.\nOferta válida mientras haya existencias.", 'price' => '199.00', 'original_price' => '329.00', 'store' => 'Liverpool'],
            ['category' => 'salud-y-belleza', 'title' => 'Shampoo y acondicionador paquete doble', 'description' => "Dos productos para el cuidado diario del cabello.\nDescuento aplicado en la presentación participante.", 'price' => '139.00', 'original_price' => '219.00', 'store' => 'Walmart'],
            ['category' => 'salud-y-belleza', 'title' => 'Cepillo eléctrico para dientes', 'description' => "Cabezal intercambiable y temporizador de cepillado.\nPrecio especial por tiempo limitado.", 'price' => '499.00', 'original_price' => '799.00', 'store' => 'Coppel'],
            ['category' => 'salud-y-belleza', 'title' => 'Kit de cuidado facial de 4 piezas', 'description' => "Incluye limpiador, hidratante y productos de rutina diaria.\nEnvío gratis a partir de $599.", 'price' => '599.00', 'original_price' => '999.00', 'store' => 'Sears'],
            ['category' => 'salud-y-belleza', 'title' => 'Secadora de cabello compacta', 'description' => "Diseño plegable para guardar o llevar de viaje.\nDescuento directo en compra en línea.", 'price' => '349.00', 'original_price' => '549.00', 'store' => 'Bodega Aurrerá'],
            ['category' => 'salud-y-belleza', 'title' => 'Multivitamínico en presentación de 90 tabletas', 'description' => "Presentación para tres meses de uso según indicaciones del envase.\nConsulta disponibilidad y condiciones de entrega.", 'price' => '229.00', 'original_price' => '379.00', 'store' => 'Costco'],
        ];
    }

    /**
     * @return list<int>
     */
    private function votesFor(int $index): array
    {
        $patterns = [
            [1, 1, 1, 1, 1],
            [1, 1, 1],
            [],
            [1, 1, 1, 1, -1],
            [-1],
            [1, 1],
            [1, 1, 1, 1],
            [1],
            [1, 1, 1, -1],
            [1, 1],
            [1, 1, 1, 1, -1],
            [1, 1, 1, 1],
        ];

        return $patterns[$index % count($patterns)];
    }

    /**
     * @return list<string>
     */
    private function commentsFor(int $index): array
    {
        $comments = [
            'Lo compré ayer y llegó rápido.',
            '¿Alguien sabe si aplica en tienda física?',
            'Ya estaba más barato la semana pasada.',
            'Buen precio, el envío me salió gratis.',
            'Me funcionó el cupón al pagar.',
            'La promoción sigue activa por ahora.',
        ];
        $count = $index % 5;

        return $count === 0 ? [] : array_map(
            static fn (int $commentIndex): string => $comments[($index + $commentIndex) % count($comments)],
            range(0, $count - 1),
        );
    }

    private function storeUrl(string $store): string
    {
        return match ($store) {
            'Amazon' => 'https://www.amazon.com.mx/',
            'Walmart' => 'https://www.walmart.com.mx/',
            'Liverpool' => 'https://www.liverpool.com.mx/',
            'Bodega Aurrerá' => 'https://www.bodegaaurrera.com.mx/',
            'Mercado Libre' => 'https://www.mercadolibre.com.mx/',
            'Coppel' => 'https://www.coppel.com/',
            'Costco' => 'https://www.costco.com.mx/',
            'Sears' => 'https://www.sears.com.mx/',
            'Steam' => 'https://store.steampowered.com/',
            'Cinemex' => 'https://www.cinemex.com/',
            default => throw new LogicException("Tienda demo no configurada: {$store}"),
        };
    }
}
