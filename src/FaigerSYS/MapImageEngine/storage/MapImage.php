<?php

namespace FaigerSYS\MapImageEngine\storage;

use InvalidArgumentException;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\utils\BinaryStream;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Throwable;
use function array_map;

class MapImage{

	/**
	 * Image parsed succesfully
	 */
	const R_OK = 1;
	/**
	 * Image corrupted or has unsupported format
	 */
	const R_CORRUPTED = 2;
	/**
	 * Image has unsupported API version
	 */
	const R_UNSUPPORTED_VERSIONS = 3;

	/**
	 * Curren version of MIE image binary
	 */
	const CURRENT_VERSION = 2;
	/**
	 * List of supported versions of MIE image binary
	 */
	const SUPPORTED_VERSIONS = [2];

	/** @var int */
	private $blocks_width;
	private $blocks_height;

	/** @var int */
	private $default_chunk_width;
	private $default_chunk_height;

	/** @var MapImageChunk[][] */
	private $chunks = [];

	/** @var UUID */
	private $uuid;

	/**
	 * @param int               $blocks_width
	 * @param int               $blocks_height
	 * @param MapImageChunk[][] $chunks
	 * @param Uuid              $uuid
	 * @param int               $default_chunk_width
	 * @param int               $default_chunk_height
	 */
	public function __construct(int $blocks_width, int $blocks_height, array $chunks = [], UuidInterface $uuid = null, int $default_chunk_width = 128, int $default_chunk_height = 128){
		if($blocks_width < 0 || $blocks_height < 0){
			throw new InvalidArgumentException('Blocks width/height must be greater than 0');
		}

		$this->blocks_width = $blocks_width;
		$this->blocks_height = $blocks_height;
		$this->uuid = $uuid ?? UUID::uuid4();
		$this->default_chunk_width = $default_chunk_width;
		$this->default_chunk_height = $default_chunk_height;
		$this->setChunks($chunks);
	}

	/**
	 * Returns image blocks width
	 *
	 * @return int
	 */
	public function getBlocksWidth() : int{
		return $this->blocks_width;
	}

	/**
	 * Returns image blocks height
	 *
	 * @return int
	 */
	public function getBlocksHeight() : int{
		return $this->blocks_height;
	}

	/**
	 * Sets image blocks width
	 *
	 * @param int $blocks_width
	 */
	public function setBlocksWidth(int $blocks_width) : void{
		if($blocks_width < 0){
			throw new InvalidArgumentException('Blocks width must be greater than 0');
		}
		$this->blocks_width = $blocks_width;
		$this->checkChunks();
	}

	/**
	 * Sets image blocks height
	 *
	 * @param int $blocks_height
	 */
	public function setBlocksHeight(int $blocks_height) : void{
		if($blocks_height < 0){
			throw new InvalidArgumentException('Blocks height must be greater than 0');
		}
		$this->blocks_height = $blocks_height;
		$this->checkChunks();
	}

	/**
	 * Returns the image chunk at specified position
	 *
	 * @param int $block_x
	 * @param int $block_y
	 *
	 * @return MapImageChunk|null
	 */
	public function getChunk(int $block_x, int $block_y) : ?MapImageChunk{
		return $this->chunks[$block_y][$block_x] ?? null;
	}

	/**
	 * Returns all image chunks
	 *
	 * @return MapImageChunk[][]
	 */
	public function getChunks() : array{
		return $this->chunks;
	}

	/**
	 * Sets the image chunk at specified position
	 *
	 * @param int           $block_x
	 * @param int           $block_y
	 * @param MapImageChunk $chunk
	 */
	public function setChunk(int $block_x, int $block_y, MapImageChunk $chunk){
		if($block_x < 0 || $block_y < 0){
			throw new InvalidArgumentException('Block X/Y must be greater than 0');
		}
		if($block_x >= $this->blocks_width){
			throw new InvalidArgumentException('Block X cannot be greater than width');
		}
		if($block_y >= $this->blocks_height){
			throw new InvalidArgumentException('Block Y cannot be greater than height');
		}

		$this->chunks[$block_y][$block_x] = $chunk;
	}

	/**
	 * Rewrites all image chunks
	 *
	 * @param MapImageChunk[][] $chunks
	 */
	public function setChunks(array $chunks){
		$this->chunks = $chunks;
		$this->checkChunks();
	}

	/**
	 * Generates bathed packet for all of image chunks
	 *
	 * @return PacketBatch
	 */
	public function generateMapImagesPacketBatch() : PacketBatch{
		//foreach ($this->chunks as $chunk) {
		//$stream->putPacket($chunk->generateMapImagePacket());
		//}
		return PacketBatch::fromPackets(...array_map(function(MapImageChunk $chunk){
			return $chunk->generateMapImagePacket();
		}, $this->chunks));
	}

	/**
	 * Returns the image UUID
	 *
	 * @return UUID
	 */
	public function getUUID() : UUID{
		return $this->uuid;
	}

	/**
	 * Returns the image UUID hash
	 *
	 * @return string
	 */
	public function getHashedUUID() : string{
		return hash('sha1', $this->uuid->toString());
	}

	/**
	 * Creates new MapImage object from MIE image binary
	 *
	 * @param string  $buffer
	 * @param int    &$state
	 *
	 * @return MapImage|null
	 */
	public static function fromBinary(string $buffer, &$state = null){
		try{
			$buffer = new BinaryStream($buffer);

			$header = $buffer->get(4);
			if($header !== 'MIEI'){
				$state = self::R_CORRUPTED;
				return null;
			}

			$api = $buffer->getInt();
			if(!in_array($api, self::SUPPORTED_VERSIONS)){
				$state = self::R_UNSUPPORTED_VERSIONS;
				return null;
			}

			$is_compressed = $buffer->getByte();
			if($is_compressed){
				$buffer = $buffer->getRemaining();
				$buffer = @zlib_decode($buffer);
				if($buffer === false){
					$state = self::R_CORRUPTED;
					return null;
				}

				$buffer = new BinaryStream($buffer);
			}

			/** @var Uuid $uuid */
			$uuid = Uuid::fromBytes($buffer->get(16));

			$blocks_width = $buffer->getInt();
			$blocks_height = $buffer->getInt();

			$chunks = [];
			for($block_y = 0; $block_y < $blocks_height; $block_y++){
				for($block_x = 0; $block_x < $blocks_width; $block_x++){
					$chunk_width = $buffer->getInt();
					$chunk_height = $buffer->getInt();
					$chunk_data = $buffer->get($chunk_width * $chunk_height * 4);

					$chunks[$block_y][$block_x] = new MapImageChunk($chunk_width, $chunk_height, $chunk_data);
				}
			}

			$state = self::R_OK;
			return new MapImage($blocks_width, $blocks_height, $chunks, $uuid);
		}catch(Throwable $e){
			$state = self::R_CORRUPTED;
			throw $e;
		}
	}

	private function checkChunks() : void{
		$chunks = $this->chunks;
		$this->chunks = [];
		for($y = 0; $y < $this->blocks_height; $y++){
			for($x = 0; $x < $this->blocks_width; $x++){
				$this->chunks[$y][$x] = ($chunks[$y][$x] ?? null) instanceof MapImageChunk ? $chunks[$y][$x] : MapImageChunk::generateImageChunk($this->default_chunk_width, $this->default_chunk_height);
			}
		}
	}

	public function __clone(){
		for($y = 0; $y < $this->blocks_height; $y++){
			for($x = 0; $x < $this->blocks_width; $x++){
				$this->chunks[$y][$x] = clone $this->chunks[$y][$x];
			}
		}
		$this->uuid = Uuid::uuid4();
	}

}
