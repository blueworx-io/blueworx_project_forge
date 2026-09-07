/**
 * Colour control: native swatch, hex input and brand presets. Replaces the WordPress iris picker.
 */
export interface ColorFieldProps {
  value?: string;
  /** Preset swatches; defaults to the BlueWorx brand and status colours. Pass [] to hide. */
  presets?: string[];
  onChange?: (hex: string) => void;
  className?: string;
}
export declare function ColorField(props: ColorFieldProps): JSX.Element;
