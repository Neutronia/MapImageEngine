<?php

namespace FaigerSYS\MapImageEngine;

use FaigerSYS\MapImageEngine\command\MapImageEngineCommand;
use FaigerSYS\MapImageEngine\item\FilledMap;
use FaigerSYS\MapImageEngine\storage\ImageStorage;
use FaigerSYS\MapImageEngine\storage\MapImage;
use FaigerSYS\MapImageEngine\storage\OldFormatConverter;
use FaigerSYS\MapImageEngine\TranslateStrings as TS;
use pocketmine\block\ItemFrame as BlockItemFrame;
use pocketmine\block\tile\ItemFrame as TileItemFrame;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerPostChunkSendEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\item\ItemFactory;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\MapInfoRequestPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\TextFormat as CLR;

class MapImageEngine extends PluginBase implements Listener{
	use SingletonTrait;

	public static function getInstance() : MapImageEngine{
		return self::$instance;
	}

	/** @var ImageStorage */
	private ?ImageStorage $storage = null;

	protected function onLoad() : void{
		self::setInstance($this);
	}

	protected function onEnable() : void{
		TS::init();

		$this->getLogger()->info(CLR::GOLD . TS::translate('plugin-loader.loading'));
		$this->getLogger()->info(CLR::AQUA . TS::translate('plugin-loader.info-instruction'));
		$this->getLogger()->info(CLR::AQUA . TS::translate('plugin-loader.info-long-loading'));
		$this->getLogger()->info(CLR::AQUA . TS::translate('plugin-loader.info-1.1-update'));

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		@mkdir($path = $this->getDataFolder());

		@mkdir($dir = $path . 'instructions/');
		foreach(scandir($r_dir = $this->getFile() . '/resources/instructions/') as $file){
			if($file[0] !== '.'){
				copy($r_dir . $file, $dir . $file);
			}
		}

		@mkdir($path . 'images');
		@mkdir($path . 'images/old_files');
		@mkdir($path . 'cache');

		$this->loadImages();

		$this->getServer()->getCommandMap()->register('mapimageengine', new MapImageEngineCommand());

		ItemFactory::getInstance()->register(new FilledMap());

		$this->getLogger()->info(CLR::GOLD . TS::translate('plugin-loader.loaded'));
	}

	public function loadImages(){
		$path = $this->getDataFolder() . 'images/';
		$storage = $this->storage ?? new ImageStorage;

		$files = array_filter(scandir($path), function($file) use ($path){
			return is_file($path . $file) && substr($file, -5, 5) === '.miei';
		});

		$old_files_path = $path . 'old_files/';
		$old_files = array_filter(scandir($path), function($file) use ($path){
			return is_file($path . $file) && substr($file, -4, 4) === '.mie';
		});
		foreach($old_files as $old_file){
			$new_data = OldFormatConverter::tryConvert(file_get_contents($path . $old_file));
			if($new_data !== null){
				$this->getLogger()->notice(TS::translate('image-loader.prefix', $old_file) . TS::translate('image-loader.converted'));

				$basename = pathinfo($old_file, PATHINFO_BASENAME);
				$new_path = $old_files_path . $basename;
				$i = 0;
				while(file_exists($new_path)){
					$new_path = $old_files_path . $basename . '.' . ++$i;
				}
				rename($path . $old_file, $new_path);

				$filename = pathinfo($old_file, PATHINFO_FILENAME);
				$extension = '.miei';
				$new_file = $filename . $extension;
				$i = 0;
				while(file_exists($path . $new_file)){
					$new_file = $filename . '_' . ++$i . $extension;
				}
				file_put_contents($path . $new_file, $new_data);

				unset($new_data);

				$files[] = $new_file;
			}else{
				$this->getLogger()->warning(TS::translate('image-loader.prefix', $old_file) . TS::translate('image-loader.not-converted'));
			}
		}


		foreach($files as $file){
			$image = MapImage::fromBinary(file_get_contents($path . $file), $state);
			if($image !== null){
				$name = substr($file, 0, -5);
				$state = $storage->registerImage($image, true, $name);
				switch($state){
					case ImageStorage::R_OK:
						$this->getLogger()->info(CLR::GREEN . TS::translate('image-loader.prefix', $file) . TS::translate('image-loader.success'));
						break;

					case ImageStorage::R_UUID_EXISTS:
						$this->getLogger()->info(TS::translate('image-loader.prefix', $file) . TS::translate('image-loader.err-image-exists'));
						break;

					case ImageStorage::R_NAME_EXISTS:
					case ImageStorage::R_INVALID_NAME:
						$this->getLogger()->warning(TS::translate('image-loader.prefix', $file) . TS::translate('image-loader.err-name-exists'));
						break;
				}
			}else{
				switch($state){
					case MapImage::R_CORRUPTED:
						$this->getLogger()->warning(TS::translate('image-loader.prefix', $file) . TS::translate('image-loader.err-corrupted'));
						break;
				}
			}
		}

		$this->storage = $storage;
	}

	public function getImageStorage() : ImageStorage{
		return $this->storage;
	}

	public function onRequest(DataPacketReceiveEvent $e){
		if($e->getPacket() instanceof MapInfoRequestPacket){
			$packet = $this->getImageStorage()->getCachedPacket($e->getPacket()->mapId);
			if($packet !== null){
				$packet->origin = BlockPosition::fromVector3(new Vector3(0, 0, 0));
				$e->getOrigin()->sendDataPacket($packet);
			}
			$e->cancel();
		}
	}

//	public function onChunkSend(PlayerPostChunkSendEvent $event) : void{
//		$player = $event->getPlayer();
//		$chunk = $event->getPlayer()->getWorld()->getChunk($event->getChunkX(), $event->getChunkZ());
//		if($chunk === null){
//			return;
//		}
//		$blocks = [];
//
//		foreach($chunk->getTiles() as $tile){
//			if($tile instanceof TileItemFrame){
//				$blocks[] = $tile->getPosition();
//			}
//		}
//		$blockCount = count($blocks);
//		if($blockCount > 0){
//			foreach($player->getWorld()->createBlockUpdatePackets($blocks) as $packet){
//				$player->getNetworkSession()->sendDataPacket($packet);
//			}
//		}
//	}
//
//	/**
//	 * @priority HIGH
//	 */
//	public function onChunkLoad(ChunkLoadEvent $e) : void{
//		$chunk = $e->getChunk();
//		/** @var Position[] $blocks */
//		$blocks = [];
//
//		foreach($chunk->getTiles() as $tile){
//			if($tile instanceof TileItemFrame){
//				$blocks[] = $tile->getPosition();
//			}
//		}
//		$blockCount = count($blocks);
//		if($blockCount > 0){
//			foreach($e->getWorld()->getPlayers() as $player){
//				foreach($e->getWorld()->createBlockUpdatePackets($blocks) as $packet){
//					$player->getNetworkSession()->sendDataPacket($packet);
//				}
//			}
//		}
//		foreach($chunk->getTiles() as $frame){
//			if($frame instanceof TileItemFrame){
//				$blocks[] = $frame->getPosition();
//				$frameBlock = $frame->getBlock();
//				if($frameBlock instanceof BlockItemFrame){
//					$item = $frame->getItem();
//					if($item instanceof FilledMap){
//						$item->updateMapData();
//						$frame->setItem($item);
//						$frameBlock->setFramedItem($item);
//					}
//				}
//			}
//		}
//		if(count($blocks) > 0){
//			$this->getServer()->broadcastPackets($e->getWorld()->getViewersForPosition($blocks[0]), $e->getWorld()->createBlockUpdatePackets($blocks));
//		}
//	}
}
